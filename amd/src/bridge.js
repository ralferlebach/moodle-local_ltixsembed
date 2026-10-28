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
 * The postMessage bridge between an embedding platform (consumer) and an embedded tool (provider).
 *
 * Consumer: waits for the tool's hello, answers with a welcome that carries a fresh channel nonce
 * to exactly that origin, and shows the new-window fallback when the tool reports a state that
 * needs it, or when the page's own CSP blocks the frame. A tool that never answers is normal
 * (consumer-only operation) unless the administrator declared that every tool runs the bridge
 * (setting "peerbridge"); see mode_core for the rules of the three operating modes.
 *
 * Provider: runs the capability probes, shows an inline notice when the frame cannot keep a
 * session, and reports one short state word to the embedding page - but only to origins on the
 * configured allow-list, never to "*". It never opens a window and never navigates the platform.
 *
 * Messages that fail validation are dropped silently: nothing from a rejected message is logged;
 * only a counter of dropped bridge messages is kept in a data attribute (test evidence).
 *
 * Runtime state is exposed in data attributes of the notice regions (see the templates) so that
 * browser traces can tell an embedded success from a top-level fallback.
 *
 * @module     local_ltixsembed/bridge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {collect} from 'local_ltixsembed/capability';
import {
    classify,
    detectSessionLost,
    shareableFacts,
} from 'local_ltixsembed/capability_core';
import {
    buildMessage,
    createValidator,
    PENDING_CONTEXT,
    randomToken,
    RETRY_REASON,
    TYPES,
} from 'local_ltixsembed/bridge_core';
import {
    correlationId,
    isProblem,
    mayRestart,
    providerHint,
    providerReports,
    shouldAutoOpen,
    silenceState,
} from 'local_ltixsembed/mode_core';

const SESSION_MARKER = 'lxs.signedin';

/**
 * Send a problem report to the site's log (diagnostics hand-over). Failures are ignored: the report is optional.
 *
 * @param {Object} args Arguments of local_ltixsembed_report_state.
 */
const report = (args) => {
    try {
        Ajax.call([{methodname: 'local_ltixsembed_report_state', args}])[0].catch(() => null);
    } catch (e) {
        // Reporting must never disturb the page.
    }
};

/**
 * Whether a message claims to be a bridge message (used only to count dropped ones).
 *
 * @param {*} data The message data.
 * @returns {boolean}
 */
const looksLikeBridge = (data) => data !== null && typeof data === 'object' && !Array.isArray(data)
    && Object.prototype.hasOwnProperty.call(data, 'lxs');

/**
 * Show the message that belongs to a state in a notice region.
 *
 * @param {Element} region The notice region.
 * @param {string} state One of STATES, or the consumer-local no_peer.
 */
const showState = (region, state) => {
    const box = region.querySelector('[data-region="state-message"]');
    if (box) {
        box.querySelectorAll('[data-lxs-state]').forEach((node) => {
            node.classList.toggle('d-none', node.getAttribute('data-lxs-state') !== state);
        });
        box.classList.toggle('d-none', !isProblem(state));
        box.querySelectorAll('[data-lxs-only]').forEach((node) => {
            node.classList.toggle('d-none', node.getAttribute('data-lxs-only') !== state);
        });
    }
    region.setAttribute('data-lxs-current', state);
};

/**
 * Consumer (platform) side.
 *
 * @param {Object} cfg Configuration from the PHP hook.
 */
const initConsumer = (cfg) => {
    const region = document.querySelector('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
    const frame = document.getElementById('contentframe');
    if (!region || !frame) {
        return;
    }
    // Put the notice next to the tool it is about instead of at the very top of the page.
    frame.parentNode.insertBefore(region, frame);

    const form = region.querySelector('[data-region="fallback-form"]');
    const details = region.querySelector('[data-region="manual-fallback"]');
    const set = (name, value) => region.setAttribute(`data-lxs-${name}`, String(value));
    let channelNonce = randomToken(window.crypto, 16);
    let cid = '';
    let retries = 0;
    let rejected = 0;
    let toolOrigin = null;
    let peer = 'pending';
    let timer = null;
    let autoTried = false;
    const reported = new Set();

    const updateCorrelation = async() => {
        cid = await correlationId(channelNonce, window.crypto);
        if (form) {
            form.elements.cid.value = cid;
        }
    };
    const ready = updateCorrelation();

    const reportProblem = async(code) => {
        await ready;
        if (reported.has(`${code}:${cid}`)) {
            return;
        }
        reported.add(`${code}:${cid}`);
        report({role: 'consumer', cmid: cfg.cmid, code, correlationid: cid, peer: peer === 'pending' ? 'none' : peer});
    };

    const refreshNonce = async() => {
        try {
            const result = await Ajax.call([{methodname: 'local_ltixsembed_issue_nonce', args: {cmid: cfg.cmid}}])[0];
            form.elements.nonce.value = result.nonce;
        } catch (e) {
            // Keep the token the server rendered into the page; it is valid for the configured lifetime.
        }
    };

    const apply = async(state) => {
        showState(region, state);
        if (form) {
            form.elements.peer.value = peer === 'pending' ? 'none' : peer;
        }
        if (!isProblem(state)) {
            return;
        }
        form.elements.reason.value = state;
        if (region.getAttribute('data-lxs-fallback') === 'none') {
            set('fallback', 'suggested');
        }
        if (details) {
            details.open = true;
        }
        reportProblem(state);
        await refreshNonce();
        if (shouldAutoOpen({fallbackMode: cfg.fallbackmode, state, alreadyOpened: autoTried})) {
            autoTried = true;
            set('fallback', 'auto_opened');
            form.submit();
        }
    };

    if (form) {
        // A submit by the user (button or keyboard); form.submit() in apply() does not fire this event.
        form.addEventListener('submit', () => set('fallback', 'opened'));
    }

    const arm = () => {
        window.clearTimeout(timer);
        if (toolOrigin === null && cfg.allowedorigins.length) {
            timer = window.setTimeout(() => {
                if (toolOrigin === null) {
                    peer = 'silent';
                    set('peer', peer);
                    apply(silenceState(cfg.peerbridge));
                }
            }, cfg.handshaketimeout);
        }
    };

    if (details) {
        details.addEventListener('toggle', () => {
            if (details.open) {
                refreshNonce();
            }
        });
    }

    if (!cfg.allowedorigins.length) {
        // Without trusted tool origins the bridge stays off; the manual option still works.
        peer = 'none';
        set('peer', peer);
        showState(region, silenceState('auto'));
        return;
    }

    const validator = createValidator({
        allowedOrigins: cfg.allowedorigins,
        getExpectedSource: () => frame.contentWindow,
        getNonce: () => channelNonce,
        getContext: () => cfg.ctx,
    });

    window.addEventListener('message', (event) => {
        const result = validator.validate(event);
        if (!result.ok) {
            if (looksLikeBridge(event.data)) {
                rejected++;
                set('rejected', rejected);
                set('lastreject', result.reason);
            }
            return;
        }
        const message = result.message;
        if (message.type === TYPES.HELLO) {
            if (toolOrigin !== null && event.origin !== toolOrigin) {
                return;
            }
            toolOrigin = event.origin;
            peer = 'bridge';
            set('peer', peer);
            window.clearTimeout(timer);
            event.source.postMessage(buildMessage({type: TYPES.WELCOME, ctx: cfg.ctx, nonce: channelNonce},
                {crypto: window.crypto, now: Date.now()}), event.origin);
        } else if (message.type === TYPES.STATE && event.origin === toolOrigin) {
            apply(message.data.state);
        } else if (message.type === TYPES.RETRY && mayRestart({retries, fromChannelPeer: event.origin === toolOrigin})) {
            // The user granted the tool storage access in its frame (Storage Access API). Start the launch
            // again from the platform so that the OIDC login runs with the granted access. The URL is the
            // one the page itself rendered; nothing from the message is used.
            retries++;
            set('retries', retries);
            reportProblem('storage_access_retry');
            toolOrigin = null;
            peer = 'pending';
            set('peer', peer);
            channelNonce = randomToken(window.crypto, 16);
            updateCorrelation();
            showState(region, 'pending');
            frame.setAttribute('src', frame.getAttribute('src'));
        }
    });

    document.addEventListener('securitypolicyviolation', (event) => {
        if (/^(frame|child)-src/.test(event.violatedDirective)) {
            apply('blocked_frame');
        }
    });

    frame.addEventListener('load', arm);
    arm();
};

/**
 * Provider (tool) side.
 *
 * @param {Object} cfg Configuration from the PHP hook.
 */
const initProvider = async(cfg) => {
    if (window.parent === window) {
        return;
    }
    const region = document.querySelector('[data-region="local_ltixsembed-notice"][data-role="provider"]');
    const set = (name, value) => {
        if (region) {
            region.setAttribute(`data-lxs-${name}`, String(value));
        }
    };
    const showHint = (channelOpen) => {
        if (!region) {
            return;
        }
        const which = providerHint(channelOpen);
        region.querySelectorAll('[data-lxs-hint]').forEach((node) => {
            node.classList.toggle('d-none', node.getAttribute('data-lxs-hint') !== which);
        });
        set('channel', channelOpen ? 'open' : 'none');
    };

    let signedBefore = false;
    try {
        signedBefore = window.sessionStorage.getItem(SESSION_MARKER) === '1';
        if (cfg.loggedin) {
            window.sessionStorage.setItem(SESSION_MARKER, '1');
        }
    } catch (e) {
        signedBefore = false;
    }

    let nonce = null;
    let ctx = null;
    let parentOrigin = null;
    let cid = '';
    const env = () => ({crypto: window.crypto, now: Date.now()});
    const reported = new Set();
    const reportCode = (code) => {
        if (!cfg.loggedin || reported.has(code)) {
            return;
        }
        reported.add(code);
        report({role: 'provider', cmid: 0, code, correlationid: cid, peer: nonce === null ? 'silent' : 'bridge'});
    };

    const facts = await collect({
        window,
        document,
        token: randomToken(window.crypto, 8),
        allowedOrigins: cfg.allowedorigins,
    });
    facts.sessionLost = detectSessionLost(signedBefore, cfg.loggedin, cfg.loginpage);
    const state = classify(facts);
    // Until a platform opens a channel the attempt id is random; the welcome replaces it with the shared one.
    cid = await correlationId(randomToken(window.crypto, 16), window.crypto);
    if (region) {
        showState(region, state);
    }
    showHint(false);

    // Report a problem once the handshake had its chance, so that platform and tool share the correlation id.
    if (providerReports(state, cfg.loggedin)) {
        window.setTimeout(() => reportCode(state), 3500);
    }

    const button = region ? region.querySelector('[data-action="request-storage-access"]') : null;
    if (button) {
        button.addEventListener('click', async() => {
            const outcome = region.querySelector('[data-region="storage-access-outcome"]');
            let granted = false;
            try {
                // Must run in this click handler: the browser demands a user gesture.
                await document.requestStorageAccess();
                granted = true;
            } catch (e) {
                granted = false;
            }
            // With a channel the platform restarts the launch; without one the user reopens the activity.
            const result = granted ? (nonce !== null && parentOrigin !== null ? 'granted' : 'granted_standalone') : 'denied';
            if (outcome) {
                outcome.classList.remove('d-none');
                outcome.setAttribute('data-lxs-outcome', result);
                outcome.querySelectorAll('[data-lxs-result]').forEach((node) => {
                    node.classList.toggle('d-none', node.getAttribute('data-lxs-result') !== result);
                });
            }
            reportCode(granted ? 'storage_access_granted' : 'storage_access_denied');
            if (result === 'granted') {
                window.parent.postMessage(buildMessage({
                    type: TYPES.RETRY,
                    ctx,
                    nonce,
                    data: {reason: RETRY_REASON},
                }, env()), parentOrigin);
            }
        });
    }
    if (!cfg.allowedorigins.length) {
        return;
    }

    const validator = createValidator({
        allowedOrigins: cfg.allowedorigins,
        getExpectedSource: () => window.parent,
        getNonce: () => nonce,
        getContext: () => ctx,
    });

    window.addEventListener('message', async(event) => {
        const result = validator.validate(event);
        if (!result.ok || result.message.type !== TYPES.WELCOME || nonce !== null) {
            return;
        }
        nonce = result.message.nonce;
        ctx = result.message.ctx;
        parentOrigin = event.origin;
        showHint(true);
        window.parent.postMessage(buildMessage({
            type: TYPES.STATE,
            ctx,
            nonce,
            data: {state, facts: shareableFacts(facts)},
        }, env()), parentOrigin);
        cid = await correlationId(nonce, window.crypto);
    });

    const hello = () => {
        if (nonce !== null) {
            return;
        }
        cfg.allowedorigins.forEach((origin) => {
            window.parent.postMessage(buildMessage({type: TYPES.HELLO, ctx: PENDING_CONTEXT}, env()), origin);
        });
    };
    [0, 400, 1200, 3000].forEach((delay) => window.setTimeout(hello, delay));
};

/**
 * Start the bridge.
 *
 * @param {Object} cfg Configuration from the PHP hook.
 */
export const init = (cfg) => {
    if (cfg.role === 'consumer') {
        initConsumer(cfg);
    } else if (cfg.role === 'provider') {
        initProvider(cfg);
    }
};
