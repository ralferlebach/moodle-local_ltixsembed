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
 * Static security rules for the AMD sources (CE-02): every outgoing postMessage names an explicit target
 * origin variable (never "*"), listeners never trust event.data before the validator ran, and the built
 * files carry the same rules.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, readdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join} from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'amd');
const files = (dir, suffix) => readdirSync(join(root, dir)).filter((f) => f.endsWith(suffix)).map((f) => join(root, dir, f));

test('no postMessage call targets "*" in the sources or in the build', () => {
    for (const file of [...files('src', '.js'), ...files('build', '.min.js')]) {
        const code = readFileSync(file, 'utf8');
        assert.doesNotMatch(code, /postMessage\([^;]*,\s*["'`]\*["'`]\s*\)/, `${file} posts to "*"`);
    }
});

test('the bridge sends only to origins taken from the validated configuration or from a validated event', () => {
    const code = readFileSync(join(root, 'src', 'bridge.js'), 'utf8');
    const calls = [...code.matchAll(/postMessage\(([\s\S]*?)\);/g)].map((m) => m[1]);
    assert.ok(calls.length >= 3);
    for (const call of calls) {
        assert.match(call, /(event\.origin|parentOrigin|origin)\s*$/, `unexpected target in postMessage(${call})`);
    }
});

test('the message listeners validate before they use any payload', () => {
    const code = readFileSync(join(root, 'src', 'bridge.js'), 'utf8');
    const listeners = code.split("window.addEventListener('message'").slice(1);
    assert.equal(listeners.length, 2);
    for (const body of listeners) {
        const validate = body.indexOf('validator.validate(event)');
        const use = body.search(/event\.data|message\.data|result\.message/);
        assert.ok(validate !== -1 && validate < use, 'validate must come first');
    }
});

test('the retry handler restarts the launch from the rendered src and uses nothing from the message', () => {
    const code = readFileSync(join(root, 'src', 'bridge.js'), 'utf8');
    const handler = code.slice(code.indexOf('TYPES.RETRY &&'), code.indexOf('TYPES.RETRY &&') + 900);
    assert.match(handler, /frame\.setAttribute\('src', frame\.getAttribute\('src'\)\)/);
    assert.doesNotMatch(handler, /message\.data|message\.url|event\.data/);
});

test('no module opens windows or navigates another window: only the platform form submit opens the new window', () => {
    for (const file of [...files('src', '.js'), ...files('build', '.min.js')]) {
        const code = readFileSync(file, 'utf8');
        assert.doesNotMatch(code, /window\.open\s*\(/, `${file} calls window.open`);
        assert.doesNotMatch(code, /(top|parent|opener)\.location/, `${file} navigates another window`);
        assert.doesNotMatch(code, /\.location\.(href|assign|replace)\s*[=(]/, `${file} navigates`);
    }
    const bridge = readFileSync(join(root, 'src', 'bridge.js'), 'utf8');
    const provider = bridge.slice(bridge.indexOf('const initProvider'));
    assert.doesNotMatch(provider, /form\.submit|\.submit\(\)/, 'the tool side never submits the fallback form');
    assert.equal((bridge.match(/^\s*form\.submit\(\);/gm) || []).length, 1, 'exactly one automatic submit, guarded by shouldAutoOpen');
    const consumer = bridge.slice(bridge.indexOf('const initConsumer'), bridge.indexOf('const initProvider'));
    const submit = consumer.indexOf('form.submit()');
    assert.ok(consumer.lastIndexOf('shouldAutoOpen(', submit) !== -1 && consumer.lastIndexOf('shouldAutoOpen(', submit) > consumer.lastIndexOf('const apply', submit));
});

test('the diagnostics report carries only the fixed fields: no URL, no facts, no message content', () => {
    const bridge = readFileSync(join(root, 'src', 'bridge.js'), 'utf8');
    const calls = [...bridge.matchAll(/report\(\{([^}]*)\}\)/g)].map((m) => m[1]);
    assert.equal(calls.length, 2);
    for (const call of calls) {
        const keys = call.split(',').map((part) => part.split(':')[0].trim()).filter(Boolean);
        assert.deepEqual(keys.sort(), ['cmid', 'code', 'correlationid', 'peer', 'role']);
        assert.doesNotMatch(call, /event\.|message\.|facts|location|href/);
    }
});
