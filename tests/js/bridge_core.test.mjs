// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
 * Unit tests of the postMessage validation core, run with `node --test tests/js/`.
 *
 * Positive paths first, then one negative test per rule: forged origin, wrong source, forged or
 * missing nonce, wrong context, wrong type, expired, future, over-long lifetime, replay, malformed
 * envelope, extra fields, forged state payloads and flooding.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {
    buildMessage, createValidator, isOriginAllowed, isValidStateData, KNOWN_STATES, MAX_LIFETIME_MS, MAX_SEEN,
    normalizeOrigin, PENDING_CONTEXT, randomToken, RETRY_REASON, safeEqual, TYPES,
} from '../../amd/src/bridge_core.js';
import {STATES} from '../../amd/src/capability_core.js';

const PLATFORM = 'https://platform.example.org';
const TOOL = 'https://tool.example.org';
const EVIL = 'https://evil.example.net';
const NONCE = 'a'.repeat(32);
const CTX = 'cm:42';

/** A clock the tests can move. */
const makeClock = (start = 1_000_000) => {
    const clock = {t: start, now: () => clock.t};
    return clock;
};

/** Validator as the platform runs it: messages must come from the iframe window. */
const platformSide = (clock, overrides = {}) => {
    const frameWindow = {name: 'iframe-window'};
    const validator = createValidator({
        allowedOrigins: [TOOL],
        getExpectedSource: () => frameWindow,
        getNonce: () => NONCE,
        getContext: () => CTX,
        now: clock.now,
        ...overrides,
    });
    return {validator, frameWindow};
};

const env = (clock) => ({crypto: webcrypto, now: clock.t});
const event = (origin, source, data) => ({origin, source, data});
const stateMessage = (clock, extra = {}) => ({
    ...buildMessage({type: TYPES.STATE, ctx: CTX, nonce: NONCE, data: {state: STATES.STORAGE_UNAVAILABLE}}, env(clock)),
    ...extra,
});

test('known states of the bridge match the states of the capability core', () => {
    assert.deepEqual([...KNOWN_STATES].sort(), Object.values(STATES).sort());
});

test('a well-formed state message from the allowed origin and the expected window is accepted', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const result = validator.validate(event(TOOL, frameWindow, stateMessage(clock)));
    assert.equal(result.ok, true);
    assert.equal(result.message.data.state, 'storage_unavailable');
});

test('hello needs no nonce and must use the pending context', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const hello = buildMessage({type: TYPES.HELLO, ctx: PENDING_CONTEXT}, env(clock));
    assert.equal(validator.validate(event(TOOL, frameWindow, hello)).ok, true);
    const wrong = buildMessage({type: TYPES.HELLO, ctx: CTX}, env(clock));
    assert.deepEqual(validator.validate(event(TOOL, frameWindow, wrong)), {ok: false, reason: 'context'});
});

test('hello must not carry a nonce', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const hello = buildMessage({type: TYPES.HELLO, ctx: PENDING_CONTEXT, nonce: NONCE}, env(clock));
    assert.deepEqual(validator.validate(event(TOOL, frameWindow, hello)), {ok: false, reason: 'nonce'});
});

test('a tool accepts a welcome from its parent and learns the nonce and context', () => {
    const clock = makeClock();
    const parent = {name: 'parent'};
    const validator = createValidator({
        allowedOrigins: [PLATFORM], getExpectedSource: () => parent, getNonce: () => null, getContext: () => null,
        now: clock.now,
    });
    const welcome = buildMessage({type: TYPES.WELCOME, ctx: CTX, nonce: NONCE}, env(clock));
    const result = validator.validate(event(PLATFORM, parent, welcome));
    assert.equal(result.ok, true);
    assert.equal(result.message.nonce, NONCE);
    assert.equal(result.message.ctx, CTX);
});

test('a welcome without a well-formed nonce is rejected', () => {
    const clock = makeClock();
    const parent = {};
    const validator = createValidator({
        allowedOrigins: [PLATFORM], getExpectedSource: () => parent, getNonce: () => null, getContext: () => null,
        now: clock.now,
    });
    for (const nonce of [undefined, '', 'short', 'g'.repeat(32), 123]) {
        const welcome = {...buildMessage({type: TYPES.WELCOME, ctx: CTX, nonce: NONCE}, env(clock)), nonce};
        assert.equal(validator.validate(event(PLATFORM, parent, welcome)).reason, 'nonce');
    }
});

test('forged origins are rejected, however similar they look', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const forged = [
        EVIL,
        'null',
        '',
        undefined,
        TOOL + '.evil.net',
        'https://evil.' + TOOL.slice(8),
        'http://tool.example.org',
        TOOL + ':8443',
        TOOL + '/',
        TOOL.toUpperCase(),
        'https://tool.example.org@evil.net',
    ];
    for (const origin of forged) {
        const result = validator.validate(event(origin, frameWindow, stateMessage(clock)));
        assert.deepEqual(result, {ok: false, reason: 'origin'}, `origin ${origin}`);
    }
});

test('the right origin from the wrong window is rejected', () => {
    const clock = makeClock();
    const {validator} = platformSide(clock);
    const otherWindow = {name: 'another-frame'};
    assert.deepEqual(validator.validate(event(TOOL, otherWindow, stateMessage(clock))), {ok: false, reason: 'source'});
    assert.deepEqual(validator.validate(event(TOOL, null, stateMessage(clock))), {ok: false, reason: 'source'});
    assert.deepEqual(validator.validate(event(TOOL, undefined, stateMessage(clock))), {ok: false, reason: 'source'});
});

test('a missing expected source never matches an absent event source', () => {
    const clock = makeClock();
    const {validator} = platformSide(clock, {getExpectedSource: () => null});
    assert.equal(validator.validate(event(TOOL, null, stateMessage(clock))).reason, 'source');
});

test('forged, missing, truncated or mistyped nonces are rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const wrong = ['b'.repeat(32), NONCE.slice(1), NONCE + 'a', '', undefined, 42, null];
    for (const nonce of wrong) {
        const message = {...stateMessage(clock), nonce};
        if (nonce === undefined) {
            delete message.nonce;
        }
        assert.equal(validator.validate(event(TOOL, frameWindow, message)).reason, 'nonce', String(nonce));
    }
});

test('a state message before the handshake (no channel nonce yet) is rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock, {getNonce: () => null});
    assert.equal(validator.validate(event(TOOL, frameWindow, stateMessage(clock))).reason, 'nonce');
});

test('a message for another activity is rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const message = {...stateMessage(clock), ctx: 'cm:43'};
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).reason, 'context');
    assert.equal(validator.validate(event(TOOL, frameWindow, {...stateMessage(clock), ctx: 'cm:42; drop'})).reason, 'context');
});

test('unknown types and versions are rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    for (const type of ['launch', 'token', 'eval', '', 'STATE', 'constructor', '__proto__', 7]) {
        assert.equal(validator.validate(event(TOOL, frameWindow, {...stateMessage(clock), type})).reason, 'type', String(type));
    }
    assert.equal(validator.validate(event(TOOL, frameWindow, {...stateMessage(clock), lxs: 2})).reason, 'version');
});

test('expired messages are rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const message = stateMessage(clock);
    clock.t += 31000;
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).reason, 'expired');
});

test('messages from the future or with an excessive lifetime are rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const future = {...stateMessage(clock), iat: clock.t + 60000, exp: clock.t + 90000};
    assert.equal(validator.validate(event(TOOL, frameWindow, future)).reason, 'lifetime');
    const long = {...stateMessage(clock), exp: clock.t + MAX_LIFETIME_MS + 1};
    assert.equal(validator.validate(event(TOOL, frameWindow, long)).reason, 'lifetime');
    const backwards = {...stateMessage(clock), iat: clock.t + 10, exp: clock.t + 5};
    assert.equal(validator.validate(event(TOOL, frameWindow, backwards)).reason, 'lifetime');
});

test('a replayed message is rejected, also after other traffic', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const message = stateMessage(clock);
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).ok, true);
    assert.equal(validator.validate(event(TOOL, frameWindow, stateMessage(clock))).ok, true);
    assert.deepEqual(validator.validate(event(TOOL, frameWindow, message)), {ok: false, reason: 'replay'});
});

test('a rejected message is not remembered as seen', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const message = stateMessage(clock);
    assert.equal(validator.validate(event(EVIL, frameWindow, message)).reason, 'origin');
    assert.equal(validator.seenCount(), 0);
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).ok, true);
});

test('seen ids are forgotten after expiry, but an expired message stays rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const message = stateMessage(clock);
    validator.validate(event(TOOL, frameWindow, message));
    clock.t += 120000;
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).reason, 'expired');
    assert.equal(validator.seenCount(), 0);
});

test('flooding stops at the cap instead of forgetting ids that could then be replayed', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    for (let i = 0; i < MAX_SEEN; i++) {
        assert.equal(validator.validate(event(TOOL, frameWindow, stateMessage(clock))).ok, true);
    }
    assert.equal(validator.validate(event(TOOL, frameWindow, stateMessage(clock))).reason, 'flood');
});

test('malformed envelopes are rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const base = stateMessage(clock);
    const cases = {
        'not an object': 'hello',
        'null': null,
        'array': [base],
        'extra field': {...base, extra: 1},
        'prototype field': JSON.parse(JSON.stringify({...base, __proto__x: 1})),
        'bad mid': {...base, mid: 'xyz'},
        'uppercase mid': {...base, mid: 'A'.repeat(32)},
        'string iat': {...base, iat: String(base.iat)},
        'NaN exp': {...base, exp: NaN},
    };
    for (const [label, data] of Object.entries(cases)) {
        const result = validator.validate(event(TOOL, frameWindow, data));
        assert.equal(result.ok, false, label);
    }
    assert.equal(validator.validate(null).ok, false);
    assert.equal(validator.validate('x').ok, false);
});

test('forged state payloads are rejected', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const payloads = [
        {state: 'hacked'},
        {state: STATES.EMBEDDED, extra: '<script>'},
        {state: STATES.EMBEDDED, facts: {framed: 'yes'}},
        {state: STATES.EMBEDDED, facts: {userAgent: 'x'}},
        {state: STATES.EMBEDDED, facts: {storageApi: 'maybe'}},
        {state: STATES.EMBEDDED, facts: null},
        {facts: {}},
        [],
        null,
        'embedded',
    ];
    for (const data of payloads) {
        const message = {...buildMessage({type: TYPES.STATE, ctx: CTX, nonce: NONCE, data: {state: 'embedded'}}, env(clock)), data};
        assert.equal(validator.validate(event(TOOL, frameWindow, message)).reason, 'data', JSON.stringify(data));
    }
    assert.equal(isValidStateData({state: STATES.EMBEDDED, facts: {framed: true, cookieOk: false, partitionedOk: true,
        storageApi: 'denied', platformStorage: 'unknown'}}), true);
});

test('welcome and hello payloads must be empty objects', () => {
    const clock = makeClock();
    const parent = {};
    const validator = createValidator({
        allowedOrigins: [PLATFORM], getExpectedSource: () => parent, getNonce: () => null, getContext: () => null,
        now: clock.now,
    });
    const welcome = buildMessage({type: TYPES.WELCOME, ctx: CTX, nonce: NONCE, data: {redirect: 'https://evil.net'}}, env(clock));
    assert.equal(validator.validate(event(PLATFORM, parent, welcome)).reason, 'data');
});

test('a message that was valid for one validator is not accepted by a validator with another nonce', () => {
    const clock = makeClock();
    const a = platformSide(clock);
    const b = platformSide(clock, {getNonce: () => 'c'.repeat(32)});
    const message = stateMessage(clock);
    assert.equal(a.validator.validate(event(TOOL, a.frameWindow, message)).ok, true);
    assert.equal(b.validator.validate(event(TOOL, b.frameWindow, message)).reason, 'nonce');
});

test('origin helpers', () => {
    assert.equal(isOriginAllowed(TOOL, [TOOL]), true);
    assert.equal(isOriginAllowed('null', ['null']), false);
    assert.equal(isOriginAllowed(TOOL, []), false);
    assert.equal(isOriginAllowed(TOOL, null), false);
    assert.equal(normalizeOrigin('https://Tool.Example.org:443'), 'https://tool.example.org');
    assert.equal(normalizeOrigin('https://tool.example.org:8443'), 'https://tool.example.org:8443');
    for (const bad of ['', 'tool.example.org', 'ftp://x.org', 'https://x.org/path', 'https://u:p@x.org', 'https://x.org?q=1',
        'https://x.org#f', 'https://*.x.org', 'https://x.org\\@evil.net', 'javascript:alert(1)', ' https://x.org', null, 5]) {
        assert.equal(normalizeOrigin(bad), null, String(bad));
    }
});

test('safeEqual and randomToken', () => {
    assert.equal(safeEqual('abc', 'abc'), true);
    assert.equal(safeEqual('abc', 'abd'), false);
    assert.equal(safeEqual('abc', 'ab'), false);
    assert.equal(safeEqual('abc', 5), false);
    const a = randomToken(webcrypto, 16);
    const b = randomToken(webcrypto, 16);
    assert.match(a, /^[a-f0-9]{32}$/);
    assert.notEqual(a, b);
});

test('buildMessage never exceeds the maximum lifetime and omits an absent nonce', () => {
    const clock = makeClock();
    const message = buildMessage({type: TYPES.HELLO, ctx: PENDING_CONTEXT}, {...env(clock), lifetimeMs: 10 * MAX_LIFETIME_MS});
    assert.equal(message.exp - message.iat, MAX_LIFETIME_MS);
    assert.equal('nonce' in message, false);
});

// ---- "retry": the tool tells the platform that the user granted storage access (CE-01 mitigation) ----

const retryMessage = (clock, data = {reason: RETRY_REASON}, extra = {}) => ({
    ...buildMessage({type: TYPES.RETRY, ctx: CTX, nonce: NONCE, data}, env(clock)),
    ...extra,
});

test('retry with the fixed reason, right origin, window and nonce is accepted', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const result = validator.validate(event(TOOL, frameWindow, retryMessage(clock)));
    assert.equal(result.ok, true);
    assert.equal(result.message.type, 'retry');
});

test('retry is refused for a forged origin, wrong window, missing or forged nonce and wrong context', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    assert.equal(validator.validate(event(EVIL, frameWindow, retryMessage(clock))).reason, 'origin');
    assert.equal(validator.validate(event(TOOL, {}, retryMessage(clock))).reason, 'source');
    const noNonce = retryMessage(clock);
    delete noNonce.nonce;
    assert.equal(validator.validate(event(TOOL, frameWindow, noNonce)).reason, 'nonce');
    assert.equal(validator.validate(event(TOOL, frameWindow, retryMessage(clock, undefined, {nonce: 'b'.repeat(32)}))).reason, 'nonce');
    assert.equal(validator.validate(event(TOOL, frameWindow, retryMessage(clock, undefined, {ctx: 'cm:43'}))).reason, 'context');
});

test('retry payload must be exactly the one fixed reason', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    for (const data of [{}, {reason: 'other'}, {reason: RETRY_REASON, url: 'https://evil.example.net/'}, {url: 'https://evil.example.net/'}, null, [], 'x']) {
        const message = retryMessage(clock, data);
        message.data = data;
        const result = validator.validate(event(TOOL, frameWindow, message));
        assert.equal(result.ok, false, JSON.stringify(data));
        assert.equal(result.reason, 'data');
    }
});

test('a retry message cannot be replayed or used after it expired', () => {
    const clock = makeClock();
    const {validator, frameWindow} = platformSide(clock);
    const message = retryMessage(clock);
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).ok, true);
    assert.equal(validator.validate(event(TOOL, frameWindow, message)).reason, 'replay');
    const old = retryMessage(clock);
    clock.t += 31000;
    assert.equal(validator.validate(event(TOOL, frameWindow, old)).reason, 'expired');
});
