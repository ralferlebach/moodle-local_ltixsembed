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
 * Pure decision logic of the browser capability test.
 *
 * Nothing in this module touches the DOM, cookies or the network, so it can be unit-tested with
 * plain objects (tests/js/capability_core.test.mjs). It turns a set of yes/no facts, which the
 * browser-side collector gathers with feature detection only, into one of the states below. The
 * facts are deliberately coarse: no user agent string, no screen size, no installed fonts or any
 * other value that could identify a browser or a person.
 *
 * @module     local_ltixsembed/capability_core
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The states the bridge can report. */
export const STATES = Object.freeze({
    EMBEDDED: 'embedded',
    TOP_LEVEL: 'top_level',
    STORAGE_UNAVAILABLE: 'storage_unavailable',
    SESSION_LOST: 'session_lost',
    BLOCKED_FRAME: 'blocked_frame',
    NEEDS_USER_INTERVENTION: 'needs_user_intervention',
});

/** States in which the new-window fallback is offered. */
export const FALLBACK_STATES = Object.freeze([
    STATES.STORAGE_UNAVAILABLE,
    STATES.SESSION_LOST,
    STATES.BLOCKED_FRAME,
    STATES.NEEDS_USER_INTERVENTION,
]);

/**
 * Whether a string is one of the known states.
 *
 * @param {*} value The value to test.
 * @returns {boolean}
 */
export const isState = (value) => typeof value === 'string' && Object.values(STATES).includes(value);

/**
 * Whether the fallback should be offered in a state.
 *
 * @param {string} state One of STATES.
 * @returns {boolean}
 */
export const needsFallback = (state) => FALLBACK_STATES.includes(state);

/**
 * Decide the state from the collected facts.
 *
 * Order matters: a lost session or a blocked frame is a stronger statement than the cookie probe.
 *
 * @param {Object} facts What the collector found.
 * @param {boolean} facts.framed True when the page is shown inside another page.
 * @param {boolean} facts.cookieOk A plain SameSite=None cookie could be written and read back.
 * @param {boolean} facts.partitionedOk A cookie with the Partitioned attribute could be written and read back.
 * @param {boolean|null} facts.hasStorageAccess Result of document.hasStorageAccess(), null when the API is absent.
 * @param {boolean} facts.canRequestStorageAccess True when document.requestStorageAccess exists.
 * @param {boolean} [facts.sessionLost] True when a signed-in page was seen earlier in this tab and this one is not.
 * @param {boolean} [facts.blockedFrame] True when the embedding page saw a CSP violation or no answer from the tool.
 * @returns {string} One of STATES.
 */
export const classify = (facts) => {
    if (facts.blockedFrame) {
        return STATES.BLOCKED_FRAME;
    }
    if (facts.sessionLost) {
        return STATES.SESSION_LOST;
    }
    const storageWorks = Boolean(facts.cookieOk || facts.partitionedOk);
    if (!facts.framed) {
        return storageWorks ? STATES.TOP_LEVEL : STATES.STORAGE_UNAVAILABLE;
    }
    if (storageWorks) {
        return STATES.EMBEDDED;
    }
    if (facts.canRequestStorageAccess && facts.hasStorageAccess === false) {
        return STATES.NEEDS_USER_INTERVENTION;
    }
    return STATES.STORAGE_UNAVAILABLE;
};

/**
 * Reduce facts to the few values that are allowed to cross the postMessage bridge.
 *
 * @param {Object} facts The collected facts.
 * @returns {Object} Booleans and short enumerations only.
 */
export const shareableFacts = (facts) => ({
    framed: Boolean(facts.framed),
    cookieOk: Boolean(facts.cookieOk),
    partitionedOk: Boolean(facts.partitionedOk),
    storageApi: facts.hasStorageAccess === null || facts.hasStorageAccess === undefined
        ? 'absent' : (facts.hasStorageAccess ? 'granted' : 'denied'),
    platformStorage: ['available', 'unavailable', 'unknown'].includes(facts.platformStorage)
        ? facts.platformStorage : 'unknown',
});

/**
 * Whether the session of the embedded tool was lost.
 *
 * Two independent signals:
 *  - the tool rendered its login page for a visitor who is not signed in, inside a frame. An LTI user cannot sign in
 *    there (the tool knows them only through the launch), so being sent to it means the tool's session is gone. This
 *    signal needs no browser storage, which matters because a browser that blocks third-party cookies may also block
 *    the frame's sessionStorage;
 *  - a page that was signed in marked the tab (sessionStorage, own key, no identifier) and this page is not signed in.
 *
 * @param {boolean} sawSignedInBefore The marker was present.
 * @param {boolean} signedInNow The server rendered this page for a signed-in user.
 * @param {boolean} [loginPage] The server rendered its login page for a visitor who is not signed in.
 * @returns {boolean}
 */
export const detectSessionLost = (sawSignedInBefore, signedInNow, loginPage = false) =>
    (Boolean(loginPage) && !signedInNow) || (Boolean(sawSignedInBefore) && !signedInNow);
