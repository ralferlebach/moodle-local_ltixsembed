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
 * Unit tests of the state classification, run with `node --test tests/js/`.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import {
    classify, detectSessionLost, FALLBACK_STATES, isState, needsFallback, shareableFacts, STATES,
} from '../../amd/src/capability_core.js';

const facts = (overrides = {}) => ({
    framed: true, cookieOk: true, partitionedOk: true, hasStorageAccess: true, canRequestStorageAccess: true, ...overrides,
});

test('a framed page with working cookies is embedded', () => {
    assert.equal(classify(facts()), STATES.EMBEDDED);
});

test('partitioned cookies alone are enough to be embedded (CHIPS)', () => {
    assert.equal(classify(facts({cookieOk: false, partitionedOk: true})), STATES.EMBEDDED);
});

test('plain cookies alone are enough to be embedded', () => {
    assert.equal(classify(facts({cookieOk: true, partitionedOk: false})), STATES.EMBEDDED);
});

test('a framed page without any working cookie has unavailable storage', () => {
    assert.equal(classify(facts({cookieOk: false, partitionedOk: false, hasStorageAccess: null, canRequestStorageAccess: false})),
        STATES.STORAGE_UNAVAILABLE);
});

test('storage access that could be requested means user intervention', () => {
    assert.equal(classify(facts({cookieOk: false, partitionedOk: false, hasStorageAccess: false, canRequestStorageAccess: true})),
        STATES.NEEDS_USER_INTERVENTION);
});

test('storage access that cannot be requested stays unavailable', () => {
    assert.equal(classify(facts({cookieOk: false, partitionedOk: false, hasStorageAccess: false, canRequestStorageAccess: false})),
        STATES.STORAGE_UNAVAILABLE);
});

test('a page in the top-level window is top_level, or unavailable without cookies', () => {
    assert.equal(classify(facts({framed: false})), STATES.TOP_LEVEL);
    assert.equal(classify(facts({framed: false, cookieOk: false, partitionedOk: false})), STATES.STORAGE_UNAVAILABLE);
});

test('a lost session wins over working cookies', () => {
    assert.equal(classify(facts({sessionLost: true})), STATES.SESSION_LOST);
});

test('a blocked frame wins over everything', () => {
    assert.equal(classify(facts({blockedFrame: true, sessionLost: true})), STATES.BLOCKED_FRAME);
});

test('session loss needs a signed-in page earlier and a signed-out page now', () => {
    assert.equal(detectSessionLost(true, false), true);
    assert.equal(detectSessionLost(true, true), false);
    assert.equal(detectSessionLost(false, false), false);
    assert.equal(detectSessionLost(undefined, undefined), false);
    // The tool's login page for a visitor who is not signed in: lost, even without any storage marker.
    assert.equal(detectSessionLost(false, false, true), true);
    assert.equal(detectSessionLost(false, true, true), false);
    assert.equal(detectSessionLost(false, false, false), false);
});

test('the fallback is offered for exactly the problem states', () => {
    for (const state of Object.values(STATES)) {
        assert.equal(needsFallback(state), FALLBACK_STATES.includes(state), state);
    }
    assert.equal(needsFallback(STATES.EMBEDDED), false);
    assert.equal(needsFallback(STATES.TOP_LEVEL), false);
    assert.equal(needsFallback('nonsense'), false);
});

test('isState only accepts the known states', () => {
    assert.equal(isState('embedded'), true);
    assert.equal(isState('constructor'), false);
    assert.equal(isState(null), false);
});

test('shareable facts contain only booleans and short enumerations', () => {
    const shared = shareableFacts({
        framed: true, cookieOk: false, partitionedOk: true, hasStorageAccess: false, platformStorage: 'bogus',
        userAgent: 'Mozilla/5.0', screen: '1920x1080', localStorageOk: true,
    });
    assert.deepEqual(shared, {framed: true, cookieOk: false, partitionedOk: true, storageApi: 'denied', platformStorage: 'unknown'});
    assert.equal(shareableFacts({hasStorageAccess: null}).storageApi, 'absent');
    assert.equal(shareableFacts({hasStorageAccess: true}).storageApi, 'granted');
});
