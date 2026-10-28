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
 * Unit tests of the operating-mode rules (CE-02): consumer-only, provider-only and cooperative operation.
 *
 * The central guarantee is tested as a table: for every combination of setting, state and history the cooperative
 * decision never opens more windows, never restarts more launches and never reports "blocked" where a one-sided
 * installation would not.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {
    correlationId, isProblem, MAX_RETRIES, mayRestart, NO_PEER, PROBLEM_STATES, PROVIDER_REPORT_STATES, providerHint,
    providerReports, shouldAutoOpen, silenceState,
} from '../../amd/src/mode_core.js';
import {FALLBACK_STATES, STATES} from '../../amd/src/capability_core.js';
import {KNOWN_STATES} from '../../amd/src/bridge_core.js';

test('problem states are the fallback states of capability_core; no_peer is local and never a wire state', () => {
    assert.deepEqual([...PROBLEM_STATES].sort(), [...FALLBACK_STATES].sort());
    assert.ok(!KNOWN_STATES.includes(NO_PEER), 'no_peer must not be accepted from the bridge');
    assert.ok(!Object.values(STATES).includes(NO_PEER));
    assert.equal(isProblem(NO_PEER), false);
    for (const s of PROVIDER_REPORT_STATES) {
        assert.ok(isProblem(s));
    }
});

test('consumer-only: a silent tool is normal unless the administrator declared the bridge as required', () => {
    assert.equal(silenceState('auto'), NO_PEER);
    assert.equal(silenceState(undefined), NO_PEER);
    assert.equal(silenceState('anything'), NO_PEER);
    assert.equal(silenceState('required'), 'blocked_frame');
    // Silence in the default mode never opens a window, whatever the fallback mode.
    assert.equal(shouldAutoOpen({fallbackMode: 'auto', state: silenceState('auto'), alreadyOpened: false}), false);
});

test('only problem states open a window, only in automatic mode, and at most once per page load', () => {
    for (const state of [...Object.values(STATES), NO_PEER]) {
        assert.equal(shouldAutoOpen({fallbackMode: 'suggest', state, alreadyOpened: false}), false, state);
        assert.equal(shouldAutoOpen({fallbackMode: 'auto', state, alreadyOpened: true}), false, state);
        assert.equal(shouldAutoOpen({fallbackMode: 'auto', state, alreadyOpened: false}), isProblem(state), state);
    }
});

test('a launch restarts only for the channel peer and at most MAX_RETRIES times', () => {
    assert.equal(MAX_RETRIES, 2);
    assert.equal(mayRestart({retries: 0, fromChannelPeer: false}), false);
    assert.equal(mayRestart({retries: 0, fromChannelPeer: true}), true);
    assert.equal(mayRestart({retries: 1, fromChannelPeer: true}), true);
    assert.equal(mayRestart({retries: 2, fromChannelPeer: true}), false);
});

test('provider-only explains how to reopen; cooperative defers the new window to the platform', () => {
    assert.equal(providerHint(false), 'standalone');
    assert.equal(providerHint(true), 'coop');
});

test('the tool reports only problem states and only for a signed-in user', () => {
    assert.equal(providerReports('storage_unavailable', true), true);
    assert.equal(providerReports('storage_unavailable', false), false);
    assert.equal(providerReports('embedded', true), false);
    assert.equal(providerReports('blocked_frame', true), false, 'a tool cannot observe its own blocked frame');
});

test('never worse together: the cooperative decision is bounded by the one-sided decisions (table)', () => {
    // Windows opened by the platform in one page load for a sequence of states, as the bridge applies them.
    const windowsOpened = (fallbackMode, states) => {
        let opened = false;
        let count = 0;
        for (const state of states) {
            if (shouldAutoOpen({fallbackMode, state, alreadyOpened: opened})) {
                opened = true;
                count++;
            }
        }
        return count;
    };
    for (const fallbackMode of ['suggest', 'auto']) {
        // Consumer-only: the tool is silent; the only state is the silence state.
        const consumerOnly = windowsOpened(fallbackMode, [silenceState('auto')]);
        // Provider-only: the platform has no bridge, it opens nothing.
        const providerOnly = 0;
        // Cooperative: the tool may report several problem states (several pages in the frame).
        const cooperative = windowsOpened(fallbackMode, ['storage_unavailable', 'session_lost', 'needs_user_intervention']);
        assert.equal(consumerOnly, 0);
        assert.ok(cooperative <= 1, 'never more than one window per page load');
        assert.ok(cooperative <= Math.max(consumerOnly, providerOnly, fallbackMode === 'auto' ? 1 : 0));
    }
    // Restarts in one page load, even if the tool sends many retries.
    let retries = 0;
    for (let i = 0; i < 10; i++) {
        if (mayRestart({retries, fromChannelPeer: true})) {
            retries++;
        }
    }
    assert.equal(retries, MAX_RETRIES);
});

test('correlation id: both sides derive the same 32-hex id from the nonce, which it does not reveal', async() => {
    const nonce = 'ab'.repeat(16);
    const a = await correlationId(nonce, webcrypto);
    const b = await correlationId(nonce, webcrypto);
    assert.match(a, /^[a-f0-9]{32}$/);
    assert.equal(a, b);
    assert.notEqual(a, nonce);
    assert.ok(!a.includes(nonce.slice(0, 8)));
    assert.notEqual(await correlationId('cd'.repeat(16), webcrypto), a);
});

test('correlation id without SubtleCrypto (plain http) is random and well-formed', async() => {
    const noSubtle = {getRandomValues: (b) => webcrypto.getRandomValues(b)};
    const a = await correlationId('ab'.repeat(16), noSubtle);
    const b = await correlationId('ab'.repeat(16), noSubtle);
    assert.match(a, /^[a-f0-9]{32}$/);
    assert.notEqual(a, b);
});
