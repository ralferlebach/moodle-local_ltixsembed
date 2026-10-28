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
 * Pure decisions of the three operating modes (CE-02): consumer-only, provider-only and cooperative.
 *
 * The rule behind every function here: whatever one side does on its own must still be correct when the other side
 * is absent, and the two sides together must never do more than the better of them would do alone. Concretely:
 *
 *  - a platform never concludes "blocked" from silence unless the administrator declared that every tool speaks the
 *    bridge (peer bridge "required"); otherwise silence is the normal consumer-only case and changes nothing;
 *  - only the platform opens a window, at most once per page load and only for a state that needs it;
 *  - the tool never opens a window or navigates the platform; with an open channel it leaves the new-window option to
 *    the platform, without one it explains how to reopen the activity;
 *  - a launch is restarted only after a user grant in the tool frame, at most MAX_RETRIES times per page load.
 *
 * This module imports nothing so that it runs unchanged in Node (tests/js/mode_core.test.mjs) and in the browser.
 *
 * @module     local_ltixsembed/mode_core
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Consumer-local state: the tool never answered and silence is not an error. Never sent across the bridge. */
export const NO_PEER = 'no_peer';

/** How often the platform restarts the launch after the tool reported a storage access grant (per page load). */
export const MAX_RETRIES = 2;

/** States that offer the new-window option (kept in step with capability_core.FALLBACK_STATES by a unit test). */
export const PROBLEM_STATES = Object.freeze(['storage_unavailable', 'session_lost', 'blocked_frame', 'needs_user_intervention']);

/** States the tool side reports to the diagnostics log (only while its user is signed in). */
export const PROVIDER_REPORT_STATES = Object.freeze(['storage_unavailable', 'session_lost', 'needs_user_intervention']);

/**
 * The state a platform assumes when the tool did not answer before the timeout.
 *
 * @param {string} peerBridge Setting "peerbridge": 'auto' or 'required'.
 * @returns {string} 'blocked_frame' when the tool is declared to run the bridge, otherwise NO_PEER.
 */
export const silenceState = (peerBridge) => (peerBridge === 'required' ? 'blocked_frame' : NO_PEER);

/**
 * Whether a state is a problem that the platform explains and for which it offers the new window.
 *
 * @param {string} state The state.
 * @returns {boolean}
 */
export const isProblem = (state) => PROBLEM_STATES.includes(state);

/**
 * Whether the platform opens the new window by itself now.
 *
 * @param {Object} input Decision input.
 * @param {string} input.fallbackMode Setting "fallbackmode": 'suggest' or 'auto'.
 * @param {string} input.state The state just applied.
 * @param {boolean} input.alreadyOpened The page already opened a window.
 * @returns {boolean}
 */
export const shouldAutoOpen = ({fallbackMode, state, alreadyOpened}) =>
    fallbackMode === 'auto' && isProblem(state) && !alreadyOpened;

/**
 * Whether a storage access grant may restart the launch.
 *
 * @param {Object} input Decision input.
 * @param {number} input.retries Restarts already done on this page load.
 * @param {boolean} input.fromChannelPeer The retry came from the tool the channel was opened with.
 * @returns {boolean}
 */
export const mayRestart = ({retries, fromChannelPeer}) => Boolean(fromChannelPeer) && retries < MAX_RETRIES;

/**
 * Whether the tool side shows its own "reopen" hint (provider-only) or defers to the platform (cooperative).
 *
 * @param {boolean} channelOpen The platform answered the handshake.
 * @returns {string} 'coop' or 'standalone'.
 */
export const providerHint = (channelOpen) => (channelOpen ? 'coop' : 'standalone');

/**
 * Whether the tool side records a state in the diagnostics log.
 *
 * @param {string} state The state.
 * @param {boolean} signedIn The tool page was rendered for a signed-in user (reports need a session).
 * @returns {boolean}
 */
export const providerReports = (state, signedIn) => Boolean(signedIn) && PROVIDER_REPORT_STATES.includes(state);

/**
 * Hexadecimal string of a buffer.
 *
 * @param {ArrayBuffer} buffer The buffer.
 * @returns {string}
 */
const hex = (buffer) => Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');

/**
 * Correlation id of one launch attempt for the diagnostics log.
 *
 * Both sides know the channel nonce, so both compute the same id; SHA-256 makes sure the nonce itself never reaches a
 * log. Without SubtleCrypto (plain http development sites) the id is random and correlates nothing across the sites.
 *
 * @param {string} nonce The channel nonce.
 * @param {Object} cryptoLike Object with getRandomValues and, in secure contexts, subtle.digest.
 * @returns {Promise<string>} 32 lower-case hexadecimal characters.
 */
export const correlationId = async(nonce, cryptoLike) => {
    if (cryptoLike && cryptoLike.subtle && typeof cryptoLike.subtle.digest === 'function' && typeof nonce === 'string') {
        try {
            const data = new TextEncoder().encode(`lxs-cid:${nonce}`);
            return hex(await cryptoLike.subtle.digest('SHA-256', data)).slice(0, 32);
        } catch (e) {
            // Fall through to a random id.
        }
    }
    const buffer = new Uint8Array(16);
    cryptoLike.getRandomValues(buffer);
    return hex(buffer.buffer);
};
