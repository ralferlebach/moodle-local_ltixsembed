#!/usr/bin/env node
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
 * Builds amd/build/*.min.js from amd/src/*.js without a full Moodle checkout.
 *
 * Mirrors the relevant parts of Moodle's grunt "amd" task: Babel (preset-env, ES modules to AMD with
 * the Moodle module name) followed by Terser without name mangling, plus a source map. When a Moodle
 * tree with node_modules is at hand, `npx grunt amd` from there produces the canonical result and
 * should be preferred; this script exists so the plugin can be built stand-alone.
 *
 * Usage (needs @babel/core, @babel/preset-env, @babel/plugin-transform-modules-amd and terser,
 * see package.json):  node tools/amd_build.cjs
 */

const fs = require('fs');
const path = require('path');
const babel = require('@babel/core');
const {minify} = require('terser');

const COMPONENT = 'local_ltixsembed';
const root = path.resolve(__dirname, '..');
const src = path.join(root, 'amd', 'src');
const build = path.join(root, 'amd', 'build');

(async() => {
    fs.mkdirSync(build, {recursive: true});
    for (const file of fs.readdirSync(src).filter((name) => name.endsWith('.js')).sort()) {
        const name = file.replace(/\.js$/, '');
        const filename = path.join(src, file);
        const transformed = babel.transformSync(fs.readFileSync(filename, 'utf8'), {
            filename,
            babelrc: false,
            configFile: false,
            sourceMaps: true,
            comments: false,
            compact: false,
            presets: [['@babel/preset-env', {modules: false, useBuiltIns: false}]],
            plugins: [['@babel/plugin-transform-modules-amd', {moduleIds: true, moduleId: `${COMPONENT}/${name}`}]],
        });
        const out = `${name}.min.js`;
        const result = await minify({[file]: transformed.code}, {
            mangle: false,
            sourceMap: {content: transformed.map, filename: out, url: `${out}.map`},
        });
        fs.writeFileSync(path.join(build, out), result.code + '\n');
        fs.writeFileSync(path.join(build, `${out}.map`), result.map);
        process.stdout.write(`built amd/build/${out}\n`);
    }
})().catch((error) => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exit(1);
});
