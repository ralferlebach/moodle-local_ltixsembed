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
 * Playwright configuration for the local_ltixsembed browser tests.
 *
 * Projects: chromium always; firefox and webkit when LXS_ALL_BROWSERS=1 and those browsers are installed
 * (they are NOT available on the development machine, so their results are "not verified locally").
 *
 * @module     local_ltixsembed/playwright.config
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const fs = require('fs');
const { defineConfig, devices } = require('@playwright/test');

// One id per Playwright run, inherited by the workers: run state (stack snapshot, results) lives under it.
process.env.LXS_RUN_ID = process.env.LXS_RUN_ID || String(Date.now());

/** Whether a browser of the given launcher is installed (Firefox/WebKit are optional on this machine). */
function installed(launcher) {
  try {
    return fs.existsSync(require('@playwright/test')[launcher].executablePath());
  } catch (e) {
    return false;
  }
}

const CHROMIUM_ARGS = ['--proxy-bypass-list=*.platform-a.test;*.tool-b.test;*.lxs-attacker.test;localhost;127.0.0.1'];
// Firefox: direct connection (the stack is local) and, because Playwright's Firefox profile does not read the system CA
// store that holds the stack's local CA, no certificate check. Cookies with Secure still need https and work.
const FIREFOX_USE = {
  ...devices['Desktop Firefox'],
  ignoreHTTPSErrors: true,
  launchOptions: { firefoxUserPrefs: { 'network.proxy.type': 0 } },
};
const projects = [{ name: 'chromium', use: { ...devices['Desktop Chrome'], launchOptions: { args: CHROMIUM_ARGS } } }];
if (process.env.LXS_ALL_BROWSERS === '1') {
  // Only browsers that are actually installed; a missing one is reported as "not verified", never as passed.
  if (installed('firefox')) {
    projects.push({ name: 'firefox', use: FIREFOX_USE });
  }
  if (installed('webkit')) {
    projects.push({ name: 'webkit', use: { ...devices['Desktop Safari'] } });
    projects.push({ name: 'mobile-safari', use: { ...devices['iPhone 14'] } });
  }
}

module.exports = defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.js$/,
  timeout: 120000,
  expect: { timeout: 20000 },
  workers: 1,
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.LXS_BASE_URL || 'http://localhost:8000',
    headless: true,
    // The two-site stack uses a local CA that is trusted by the system store and Chromium's NSS db.
    ignoreHTTPSErrors: false,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
  },
  projects,
});
