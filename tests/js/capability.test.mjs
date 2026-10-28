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
 * Unit tests of the browser-side probes with fake window and document objects.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import {
    collect, isFramed, probeCookie, probeLocalStorage, probePlatformStorage, probeStorageAccess,
} from '../../amd/src/capability.js';

/** A document whose cookie jar honours Max-Age and can be told to refuse writes. */
const fakeDocument = ({accept = true, rejectPartitioned = false, extra = {}} = {}) => {
    const jar = new Map();
    const writes = [];
    return {
        writes,
        jar,
        get cookie() {
            return [...jar].map(([k, v]) => `${k}=${v}`).join('; ');
        },
        set cookie(line) {
            writes.push(line);
            if (!accept || (rejectPartitioned && /Partitioned/.test(line))) {
                return;
            }
            const [pair, ...attrs] = line.split(';').map((s) => s.trim());
            const [name, value] = pair.split('=');
            if (attrs.includes('Max-Age=0')) {
                jar.delete(name);
            } else {
                jar.set(name, value);
            }
        },
        ...extra,
    };
};

const https = {protocol: 'https:'};
const http = {protocol: 'http:'};

test('a cookie that can be written and read back passes, and is removed again', () => {
    const doc = fakeDocument();
    assert.equal(probeCookie(doc, https, 'abc123', false), true);
    assert.equal(doc.jar.size, 0);
    assert.match(doc.writes[0], /SameSite=None; Secure/);
    assert.doesNotMatch(doc.writes[0], /Partitioned/);
});

test('the partitioned probe sets the Partitioned attribute and needs https', () => {
    const doc = fakeDocument();
    assert.equal(probeCookie(doc, https, 'abc123', true), true);
    assert.match(doc.writes[0], /Partitioned/);
    assert.equal(probeCookie(fakeDocument(), http, 'abc123', true), false);
});

test('over http the probe uses SameSite=Lax without Secure', () => {
    const doc = fakeDocument();
    assert.equal(probeCookie(doc, http, 'abc123', false), true);
    assert.match(doc.writes[0], /SameSite=Lax/);
    assert.doesNotMatch(doc.writes[0], /Secure/);
});

test('a refused cookie write fails the probe', () => {
    assert.equal(probeCookie(fakeDocument({accept: false}), https, 'abc123', false), false);
});

test('a browser that rejects only partitioned cookies is reported accurately', () => {
    const doc = fakeDocument({rejectPartitioned: true});
    assert.equal(probeCookie(doc, https, 'abc123', false), true);
    assert.equal(probeCookie(doc, https, 'abc123', true), false);
});

test('a document that throws on cookie access fails the probe instead of throwing', () => {
    const doc = {get cookie() {
        throw new Error('SecurityError');
    }, set cookie(v) {
        throw new Error('SecurityError');
    }};
    assert.equal(probeCookie(doc, https, 'abc123', false), false);
});

test('probes use a random name and never touch other cookies', () => {
    const doc = fakeDocument();
    doc.jar.set('MoodleSession', 'secret');
    probeCookie(doc, https, 'tok', false);
    assert.equal(doc.jar.get('MoodleSession'), 'secret');
    assert.ok(doc.writes.every((line) => line.startsWith('lxs_probe_')));
});

test('local storage probe', () => {
    const store = new Map();
    const ok = {localStorage: {setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k)}};
    assert.equal(probeLocalStorage(ok), true);
    assert.equal(store.size, 0);
    const blocked = {get localStorage() {
        throw new Error('SecurityError');
    }};
    assert.equal(probeLocalStorage(blocked), false);
});

test('Storage Access API probe: absent, granted, denied, throwing', async() => {
    assert.equal(await probeStorageAccess({}), null);
    assert.equal(await probeStorageAccess({hasStorageAccess: async() => true}), true);
    assert.equal(await probeStorageAccess({hasStorageAccess: async() => false}), false);
    assert.equal(await probeStorageAccess({hasStorageAccess: async() => {
        throw new Error('x');
    }}), null);
});

test('isFramed handles cross-origin access errors', () => {
    const self = {};
    assert.equal(isFramed({top: self, self}), false);
    assert.equal(isFramed({top: {}, self}), true);
    assert.equal(isFramed({get top() {
        throw new Error('SecurityError');
    }, self}), true);
});

/** A minimal window that records listeners and postMessage calls. */
const fakeWindow = ({framed = true} = {}) => {
    const listeners = [];
    const sent = [];
    const timers = [];
    const parent = {postMessage: (data, origin) => sent.push({data, origin})};
    const win = {
        parent: framed ? parent : null,
        location: https,
        listeners,
        sent,
        addEventListener: (type, fn) => listeners.push(fn),
        removeEventListener: (type, fn) => listeners.splice(listeners.indexOf(fn), 1),
        setTimeout: (fn, ms) => {
            timers.push({fn, ms});
            return timers.length;
        },
        clearTimeout: () => {},
        fire: () => timers.forEach((t) => t.fn()),
    };
    win.self = win;
    win.top = framed ? parent : win;
    if (!framed) {
        win.parent = win;
    }
    return {win, parent, listeners, sent, timers};
};

test('platform storage probe: unknown when not framed or without origins', async() => {
    const top = fakeWindow({framed: false});
    assert.equal(await probePlatformStorage(top.win, ['https://p.example.org'], 10), 'unknown');
    const framed = fakeWindow();
    assert.equal(await probePlatformStorage(framed.win, [], 10), 'unknown');
});

test('platform storage probe asks only the configured origins and never "*"', async() => {
    const {win, sent, timers} = fakeWindow();
    const pending = probePlatformStorage(win, ['https://p.example.org', 'https://q.example.org'], 10);
    assert.deepEqual(sent.map((s) => s.origin), ['https://p.example.org', 'https://q.example.org']);
    assert.ok(sent.every((s) => s.data.subject === 'lti.capabilities' && s.origin !== '*'));
    timers[0].fn();
    assert.equal(await pending, 'unavailable');
});

test('platform storage probe accepts an answer only from the parent and a configured origin', async() => {
    const {win, parent, listeners} = fakeWindow();
    const pending = probePlatformStorage(win, ['https://p.example.org'], 1000);
    const answer = {subject: 'lti.capabilities.response', supported_messages: [{subject: 'lti.get_data'}]};
    listeners[0]({source: {}, origin: 'https://p.example.org', data: answer});
    listeners[0]({source: parent, origin: 'https://evil.example.net', data: answer});
    assert.equal(listeners.length, 1, 'forged answers must not settle the probe');
    listeners[0]({source: parent, origin: 'https://p.example.org', data: answer});
    assert.equal(await pending, 'available');
    assert.equal(listeners.length, 0);
});

test('collect gathers all facts without throwing', async() => {
    const {win} = fakeWindow();
    win.localStorage = {setItem() {}, removeItem() {}};
    const doc = fakeDocument({extra: {hasStorageAccess: async() => false, requestStorageAccess: () => {}}});
    const facts = await collect({window: win, document: doc, token: 'tok', allowedOrigins: [], platformTimeoutMs: 1});
    assert.deepEqual(facts, {
        framed: true, cookieOk: true, partitionedOk: true, localStorageOk: true, hasStorageAccess: false,
        canRequestStorageAccess: true, platformStorage: 'unknown',
    });
});
