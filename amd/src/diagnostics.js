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
 * The browser capability test page.
 *
 * @module     local_ltixsembed/diagnostics
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_strings as getStrings} from 'core/str';
import {collect} from 'local_ltixsembed/capability';
import {classify, needsFallback} from 'local_ltixsembed/capability_core';
import {randomToken} from 'local_ltixsembed/bridge_core';

const COMPONENT = 'local_ltixsembed';

/**
 * Run the test and fill the page.
 *
 * @param {Object} cfg Configuration from the PHP page.
 */
export const init = async(cfg) => {
    const root = document.querySelector('[data-region="local_ltixsembed-diagnostics"]');
    if (!root) {
        return;
    }
    const facts = await collect({
        window,
        document,
        token: randomToken(window.crypto, 8),
        allowedOrigins: cfg.allowedorigins,
    });
    const state = classify(facts);

    const keys = ['value_yes', 'value_no', 'value_unknown', 'value_na', 'value_available', 'value_unavailable',
        'state_' + state, 'diag_recommend_none', 'diag_recommend_open'];
    const strings = await getStrings(keys.map((key) => ({key, component: COMPONENT})));
    const text = Object.fromEntries(keys.map((key, i) => [key, strings[i]]));
    const yesNo = (value) => (value ? text.value_yes : text.value_no);

    const values = {
        framed: yesNo(facts.framed),
        cookie: yesNo(facts.cookieOk),
        cookie_partitioned: yesNo(facts.partitionedOk),
        localstorage: yesNo(facts.localStorageOk),
        hassa: facts.hasStorageAccess === null ? text.value_unavailable : yesNo(facts.hasStorageAccess),
        requestsa: facts.canRequestStorageAccess ? text.value_available : text.value_unavailable,
        platformstorage: {
            available: text.value_available,
            unavailable: text.value_unavailable,
            unknown: text.value_unknown,
        }[facts.platformStorage],
        session: text.value_na,
    };
    Object.entries(values).forEach(([key, value]) => {
        const cell = root.querySelector(`[data-feature="${key}"]`);
        if (cell) {
            cell.textContent = value;
        }
    });
    root.querySelector('[data-region="state"]').textContent = text['state_' + state];
    root.querySelector('[data-region="state"]').setAttribute('data-lxs-state-value', state);
    root.querySelector('[data-region="recommendation"]').textContent =
        needsFallback(state) ? text.diag_recommend_open : text.diag_recommend_none;

    if (facts.canRequestStorageAccess && facts.hasStorageAccess === false) {
        const holder = root.querySelector('[data-region="request-access"]');
        holder.classList.remove('d-none');
        holder.querySelector('[data-action="request-access"]').addEventListener('click', async() => {
            try {
                await document.requestStorageAccess();
            } catch (e) {
                // Denied or not allowed in this context; the page simply keeps its previous result.
            }
        });
    }
};
