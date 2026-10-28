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

/**
 * Browser-side collection of the facts that capability_core classifies.
 *
 * Feature detection only. The probes write short-lived cookies with a random name and value of their
 * own and remove them again; they read nothing that was set by anything else, and no value leaves
 * the page. This module imports nothing, so tests/js/capability.test.mjs can drive it with fake
 * window and document objects.
 *
 * @module     local_ltixsembed/capability
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Whether the page is shown inside another page.
 *
 * @param {Window} win The window.
 * @returns {boolean}
 */
export const isFramed = (win) => {
    try {
        return win.top !== win.self;
    } catch (e) {
        // Reading window.top across origins can throw; that itself means a foreign frame.
        return true;
    }
};

/**
 * Write a cookie and read it back.
 *
 * @param {Document} doc The document.
 * @param {Location} loc The location (only the protocol is used).
 * @param {string} token Random token used for name and value.
 * @param {boolean} partitioned Add the Partitioned attribute (CHIPS) - needs https.
 * @returns {boolean} True when the cookie was readable right after writing it.
 */
export const probeCookie = (doc, loc, token, partitioned) => {
    const secure = loc.protocol === 'https:';
    if (partitioned && !secure) {
        return false;
    }
    const attributes = (secure ? 'SameSite=None; Secure' : 'SameSite=Lax') + (partitioned ? '; Partitioned' : '');
    const name = (partitioned ? 'lxs_probe_p_' : 'lxs_probe_') + token;
    try {
        doc.cookie = `${name}=${token}; Path=/; Max-Age=30; ${attributes}`;
        const found = String(doc.cookie).split('; ').includes(`${name}=${token}`);
        doc.cookie = `${name}=; Path=/; Max-Age=0; ${attributes}`;
        return found;
    } catch (e) {
        return false;
    }
};

/**
 * Whether local storage can be written.
 *
 * @param {Window} win The window.
 * @returns {boolean}
 */
export const probeLocalStorage = (win) => {
    try {
        win.localStorage.setItem('lxs_probe', '1');
        win.localStorage.removeItem('lxs_probe');
        return true;
    } catch (e) {
        return false;
    }
};

/**
 * Ask the Storage Access API whether this frame already has access.
 *
 * @param {Document} doc The document.
 * @returns {Promise<boolean|null>} null when the API does not exist.
 */
export const probeStorageAccess = async(doc) => {
    if (typeof doc.hasStorageAccess !== 'function') {
        return null;
    }
    try {
        return Boolean(await doc.hasStorageAccess());
    } catch (e) {
        return null;
    }
};

/**
 * Ask the embedding page whether it implements LTI platform storage.
 *
 * Sends {subject: 'lti.capabilities'} to the parent, once per configured origin and never to "*".
 * The answer is accepted only from the parent window and one of those origins.
 *
 * @param {Window} win The window.
 * @param {string[]} origins Configured platform origins.
 * @param {number} timeoutMs How long to wait.
 * @returns {Promise<string>} 'available', 'unavailable' or 'unknown' (not framed or no origin configured).
 */
export const probePlatformStorage = (win, origins, timeoutMs) => new Promise((resolve) => {
    if (!isFramed(win) || !origins.length) {
        resolve('unknown');
        return;
    }
    let timer = null;
    const done = (value) => {
        win.removeEventListener('message', handler);
        win.clearTimeout(timer);
        resolve(value);
    };
    const handler = (event) => {
        const data = event.data;
        if (event.source !== win.parent || !origins.includes(event.origin) || !data ||
                data.subject !== 'lti.capabilities.response') {
            return;
        }
        const supported = Array.isArray(data.supported_messages) ? data.supported_messages : [];
        done(supported.some((entry) => entry && entry.subject === 'lti.get_data') ? 'available' : 'unavailable');
    };
    win.addEventListener('message', handler);
    timer = win.setTimeout(() => done('unavailable'), timeoutMs);
    origins.forEach((origin) => {
        try {
            win.parent.postMessage({subject: 'lti.capabilities'}, origin);
        } catch (e) {
            // A malformed origin cannot be a valid target; the timeout covers the rest.
        }
    });
});

/**
 * Collect the facts.
 *
 * @param {Object} env The environment.
 * @param {Window} env.window The window.
 * @param {Document} env.document The document.
 * @param {string} env.token Random token for the cookie probes.
 * @param {string[]} [env.allowedOrigins] Platform origins to ask for LTI platform storage.
 * @param {number} [env.platformTimeoutMs] Time to wait for the platform's answer.
 * @returns {Promise<Object>} Facts for classify().
 */
export const collect = async(env) => {
    const win = env.window;
    const doc = env.document;
    const framed = isFramed(win);
    const [hasStorageAccess, platformStorage] = await Promise.all([
        probeStorageAccess(doc),
        probePlatformStorage(win, env.allowedOrigins || [], env.platformTimeoutMs || 600),
    ]);
    return {
        framed,
        cookieOk: probeCookie(doc, win.location, env.token, false),
        partitionedOk: probeCookie(doc, win.location, env.token, true),
        localStorageOk: probeLocalStorage(win),
        hasStorageAccess,
        canRequestStorageAccess: typeof doc.requestStorageAccess === 'function',
        platformStorage,
    };
};
