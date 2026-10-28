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

/**
 * Validation and construction of the messages exchanged between an embedding platform and an
 * embedded tool.
 *
 * This module is the security core of the bridge and has no dependency on the browser: it is
 * exercised in Node (tests/js/bridge_core.test.mjs) with forged origins, sources, nonces, contexts,
 * lifetimes and replays. A message is accepted only if EVERY check passes:
 *
 *  1. event.origin equals one of the configured origins (exact string match, no wildcard),
 *  2. event.source is the window the bridge expects (the iframe, or the parent),
 *  3. the envelope has exactly the known fields with the expected shapes and a known type,
 *  4. for typed messages after the handshake, the channel nonce matches (constant-time compare),
 *  5. the context (activity) matches,
 *  6. the message is neither expired nor from the future nor longer-lived than the maximum,
 *  7. its message id has not been seen before (replay).
 *
 * @module     local_ltixsembed/bridge_core
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Marker field and version of the envelope. */
export const PROTOCOL_VERSION = 1;

/** Message types. */
export const TYPES = Object.freeze({HELLO: 'hello', WELCOME: 'welcome', STATE: 'state', RETRY: 'retry'});

/** The only reason a "retry" message may carry: the user granted the tool storage access in the frame. */
export const RETRY_REASON = 'storage_access_granted';

/** Types that must carry the channel nonce (the others establish it). */
const NONCE_REQUIRED = Object.freeze(Object.assign(Object.create(null), {hello: false, welcome: false, state: true, retry: true}));

/** Context a tool uses in its hello, before the platform has told it the real one. */
export const PENDING_CONTEXT = 'pending';

/** Longest permitted lifetime of one message (exp - iat) in milliseconds. */
export const MAX_LIFETIME_MS = 60000;

/** Tolerated clock difference between the two windows (same device) in milliseconds. */
export const MAX_SKEW_MS = 5000;

/** Upper bound for remembered message ids; beyond it new messages are refused instead of forgetting old ids. */
export const MAX_SEEN = 1000;

/**
 * The states that may be reported. Kept in step with STATES of capability_core (a unit test compares them);
 * this module deliberately imports nothing so that it loads unchanged in Node and in the browser.
 */
export const KNOWN_STATES = Object.freeze([
    'embedded', 'top_level', 'storage_unavailable', 'session_lost', 'blocked_frame', 'needs_user_intervention',
]);

const ENVELOPE_KEYS = Object.freeze(['lxs', 'type', 'mid', 'nonce', 'ctx', 'iat', 'exp', 'data']);
const HEX_ID = /^[a-f0-9]{32}$/;
const HEX_NONCE = /^[a-f0-9]{32,64}$/;
const CONTEXT = /^[A-Za-z0-9:_-]{1,64}$/;

/**
 * Canonical origin of a configured value, or null if it is not a bare origin.
 *
 * @param {string} value For example "https://tool.example.org".
 * @returns {string|null}
 */
export const normalizeOrigin = (value) => {
    if (typeof value !== 'string' || value === '' || /[\s*\\]/.test(value)) {
        return null;
    }
    let url;
    try {
        url = new URL(value);
    } catch (e) {
        return null;
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }
    if (url.username || url.password || url.search || url.hash || (url.pathname !== '/' && url.pathname !== '')) {
        return null;
    }
    return url.origin;
};

/**
 * Exact allow-list test.
 *
 * @param {string} origin The origin reported by the browser (event.origin).
 * @param {string[]} allowed Canonical origins.
 * @returns {boolean}
 */
export const isOriginAllowed = (origin, allowed) =>
    typeof origin === 'string' && origin !== 'null' && Array.isArray(allowed) && allowed.includes(origin);

/**
 * Compare two strings without an early exit on the first difference.
 *
 * @param {string} a First value.
 * @param {string} b Second value.
 * @returns {boolean}
 */
export const safeEqual = (a, b) => {
    if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) {
        return false;
    }
    let diff = 0;
    for (let i = 0; i < a.length; i++) {
        diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }
    return diff === 0;
};

/**
 * Random hexadecimal token.
 *
 * @param {Object} cryptoLike Object with getRandomValues (window.crypto or Node's webcrypto).
 * @param {number} [bytes] Number of random bytes; the result has twice as many characters.
 * @returns {string}
 */
export const randomToken = (cryptoLike, bytes = 16) => {
    const buffer = new Uint8Array(bytes);
    cryptoLike.getRandomValues(buffer);
    return Array.from(buffer, (b) => b.toString(16).padStart(2, '0')).join('');
};

/**
 * Build an outgoing message.
 *
 * @param {Object} spec What to send.
 * @param {string} spec.type One of TYPES.
 * @param {string} spec.ctx Context (activity) the channel belongs to.
 * @param {string} [spec.nonce] Channel nonce (required for "state" and "welcome").
 * @param {Object} [spec.data] Type-specific payload.
 * @param {Object} env Environment.
 * @param {Object} env.crypto Provides getRandomValues.
 * @param {number} env.now Current time in milliseconds.
 * @param {number} [env.lifetimeMs] Lifetime of the message.
 * @returns {Object} The envelope.
 */
export const buildMessage = (spec, env) => {
    const lifetime = Math.min(env.lifetimeMs || 30000, MAX_LIFETIME_MS);
    const message = {
        lxs: PROTOCOL_VERSION,
        type: spec.type,
        mid: randomToken(env.crypto, 16),
        ctx: spec.ctx,
        iat: env.now,
        exp: env.now + lifetime,
        data: spec.data || {},
    };
    if (spec.nonce) {
        message.nonce = spec.nonce;
    }
    return message;
};

/**
 * Validate the payload of a "state" message.
 *
 * @param {*} data The payload.
 * @returns {boolean} True when it holds a known state and nothing but known short fields.
 */
export const isValidStateData = (data) => {
    if (data === null || typeof data !== 'object' || Array.isArray(data)) {
        return false;
    }
    const allowed = ['state', 'facts'];
    if (Object.keys(data).some((key) => !allowed.includes(key))) {
        return false;
    }
    if (typeof data.state !== 'string' || !KNOWN_STATES.includes(data.state)) {
        return false;
    }
    if (data.facts !== undefined) {
        const facts = data.facts;
        if (facts === null || typeof facts !== 'object' || Array.isArray(facts)) {
            return false;
        }
        const keys = ['framed', 'cookieOk', 'partitionedOk', 'storageApi', 'platformStorage'];
        if (Object.keys(facts).some((key) => !keys.includes(key))) {
            return false;
        }
        for (const key of ['framed', 'cookieOk', 'partitionedOk']) {
            if (key in facts && typeof facts[key] !== 'boolean') {
                return false;
            }
        }
        if ('storageApi' in facts && !['absent', 'granted', 'denied'].includes(facts.storageApi)) {
            return false;
        }
        if ('platformStorage' in facts && !['available', 'unavailable', 'unknown'].includes(facts.platformStorage)) {
            return false;
        }
    }
    return true;
};

/**
 * Validate the payload of a "retry" message: exactly one fixed reason, nothing else.
 *
 * @param {*} data The payload.
 * @returns {boolean}
 */
export const isValidRetryData = (data) =>
    data !== null && typeof data === 'object' && !Array.isArray(data)
    && Object.keys(data).length === 1 && data.reason === RETRY_REASON;

/**
 * Validate the payload of a "welcome" message (it only repeats the context).
 *
 * @param {*} data The payload.
 * @returns {boolean}
 */
const isValidWelcomeData = (data) =>
    data !== null && typeof data === 'object' && !Array.isArray(data) && Object.keys(data).length === 0;

/**
 * Create a validator for incoming messages.
 *
 * @param {Object} options Configuration.
 * @param {string[]} options.allowedOrigins Canonical origins that may send messages.
 * @param {function(): *} options.getExpectedSource Returns the window messages must come from.
 * @param {function(): (string|null)} options.getNonce Returns the channel nonce, or null before the handshake.
 * @param {function(): (string|null)} options.getContext Returns the expected context, or null if not yet known
 *     (a tool learns it from the welcome message).
 * @param {function(): number} [options.now] Clock in milliseconds.
 * @returns {{validate: function(Object): Object, seenCount: function(): number}}
 */
export const createValidator = (options) => {
    const now = options.now || (() => Date.now());
    const seen = new Map();

    const prune = (t) => {
        for (const [mid, exp] of seen) {
            if (exp < t - MAX_SKEW_MS) {
                seen.delete(mid);
            }
        }
    };

    const reject = (reason) => ({ok: false, reason});

    const validate = (event) => {
        prune(now());
        if (!event || typeof event !== 'object') {
            return reject('event');
        }
        if (!isOriginAllowed(event.origin, options.allowedOrigins)) {
            return reject('origin');
        }
        const expected = options.getExpectedSource();
        if (!expected || event.source !== expected) {
            return reject('source');
        }

        const m = event.data;
        if (m === null || typeof m !== 'object' || Array.isArray(m)) {
            return reject('shape');
        }
        if (Object.keys(m).some((key) => !ENVELOPE_KEYS.includes(key))) {
            return reject('shape');
        }
        if (m.lxs !== PROTOCOL_VERSION) {
            return reject('version');
        }
        if (typeof m.type !== 'string' || !Object.prototype.hasOwnProperty.call(NONCE_REQUIRED, m.type)) {
            return reject('type');
        }
        if (typeof m.mid !== 'string' || !HEX_ID.test(m.mid)) {
            return reject('id');
        }
        if (typeof m.ctx !== 'string' || !CONTEXT.test(m.ctx)) {
            return reject('context');
        }
        // A tool does not know its context yet when it says hello; it learns it from the welcome.
        if (m.type === TYPES.HELLO && m.ctx !== PENDING_CONTEXT) {
            return reject('context');
        }
        if (!Number.isFinite(m.iat) || !Number.isFinite(m.exp)) {
            return reject('shape');
        }

        // Nonce: typed messages must carry the channel nonce; "welcome" delivers it and so must carry a well-formed one.
        if (m.type === TYPES.WELCOME) {
            if (typeof m.nonce !== 'string' || !HEX_NONCE.test(m.nonce)) {
                return reject('nonce');
            }
        } else if (NONCE_REQUIRED[m.type]) {
            const expectedNonce = options.getNonce();
            if (typeof m.nonce !== 'string' || !expectedNonce || !safeEqual(m.nonce, expectedNonce)) {
                return reject('nonce');
            }
        } else if (m.nonce !== undefined) {
            return reject('nonce');
        }

        // Context: once known it must match exactly.
        const expectedContext = options.getContext();
        if (m.type !== TYPES.HELLO && expectedContext && !safeEqual(m.ctx, expectedContext)) {
            return reject('context');
        }

        // Lifetime.
        const t = now();
        if (m.exp <= t) {
            return reject('expired');
        }
        if (m.iat > t + MAX_SKEW_MS || m.exp - m.iat > MAX_LIFETIME_MS || m.exp <= m.iat) {
            return reject('lifetime');
        }

        // Payload.
        if (m.type === TYPES.STATE && !isValidStateData(m.data)) {
            return reject('data');
        }
        if (m.type === TYPES.RETRY && !isValidRetryData(m.data)) {
            return reject('data');
        }
        if (m.type === TYPES.WELCOME && !isValidWelcomeData(m.data)) {
            return reject('data');
        }
        if (m.type === TYPES.HELLO && !isValidWelcomeData(m.data)) {
            return reject('data');
        }

        // Replay.
        if (seen.has(m.mid)) {
            return reject('replay');
        }
        if (seen.size >= MAX_SEEN) {
            return reject('flood');
        }
        seen.set(m.mid, m.exp);

        return {ok: true, message: m};
    };

    return {validate, seenCount: () => seen.size};
};
