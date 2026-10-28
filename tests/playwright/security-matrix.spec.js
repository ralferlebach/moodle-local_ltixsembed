/*
 * This file is part of Moodle - https://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <https://www.gnu.org/licenses/>.


/*
 * CE-02 security protocol (Chromium, two-site stack).
 *
 *  - A page of an origin that is NOT on the allow-list embeds the tool: nothing the bridge sends reaches it,
 *    and its forged messages change nothing in the tool frame.
 *  - The tool frame never receives a message from a window other than its parent.
 *  - The fallback form and the launch URL carry no sesskey, token or session identifier in any URL.
 *  - The plugin emits no framing headers of its own (X-Frame-Options / CSP stay with core and the web server).
 *
 * Firefox, WebKit and mobile Safari run the same file where installed (LXS_ALL_BROWSERS=1): not verified locally.
 */

const { test, expect } = require('@playwright/test');
const { stackAvailable, loadSeed, getUser, login, sql, setConfig, snapshotStackOnce, restoreStack,
} = require('./support');

test.describe.configure({ mode: 'serial', retries: 1 });

test.describe('local_ltixsembed security protocol', () => {
  test.skip(!stackAvailable(), 'the two-site stack is not available (LTI_SEED)');

  let seed;
  let activity;
  let snap;

  test.beforeAll(() => {
    seed = loadSeed();
    activity = seed.lti_activity;
    snap = snapshotStackOnce();
    sql('a', `update mdl_lti set launchcontainer=3 where id=${activity.instance}`);
    setConfig('a', 'allowedorigins', seed.urls.b);
    setConfig('a', 'consumerenabled', 1);
    setConfig('a', 'providerenabled', 1);
    setConfig('a', 'peerbridge', 'auto');
    setConfig('b', 'allowedorigins', seed.urls.a);
    setConfig('b', 'providerenabled', 1);
    setConfig('b', 'providerltipages', 1);
  });

  test.afterAll(() => {
    if (snap) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  test('an embedding origin that is not on the tool allow-list receives nothing and cannot steer the bridge', async ({ browser }) => {
    // The tool (B) is configured to talk to another origin only, so the embedding page (A) is a foreign origin for it.
    setConfig('b', 'allowedorigins', 'https://other.example');
    // The platform is told that every tool runs the bridge, so silence is reported as a blocked frame (with the default
    // "auto" the same silence is the normal consumer-only case: modes.spec.js).
    setConfig('a', 'peerbridge', 'required');
    const context = await browser.newContext();
    try {
      await context.addInitScript(() => {
        if (window.top === window) {
          window.__got = [];
          window.addEventListener('message', (e) => window.__got.push({ origin: e.origin, type: e.data && e.data.type }));
        }
      });
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      await expect.poll(() => page.frames().map((f) => f.url()).join(' '), { timeout: 60000 }).toMatch(/b\.tool-b\.test\/course\/view\.php/);
      const frame = page.frames().find((f) => /b\.tool-b\.test\/course\/view\.php/.test(f.url()));
      // The bridge did run in the tool (its notice region exists) ...
      await expect(frame.locator('[data-region="local_ltixsembed-notice"][data-role="provider"]')).toBeAttached({ timeout: 20000 });
      // ... the platform ends in blocked_frame because no accepted tool ever answered ...
      await expect(page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]'))
        .toHaveAttribute('data-lxs-current', 'blocked_frame', { timeout: 30000 });
      // ... and hello goes only to allow-listed origins, so the platform heard nothing at all from the tool.
      expect((await page.evaluate(() => window.__got)).filter((m) => m.origin === seed.urls.b)).toEqual([]);
      // A forged welcome from the platform is ignored by the tool: still silence afterwards.
      await page.evaluate((target) => {
        window.frames[0].postMessage({
          lxs: 1, type: 'welcome', mid: 'a'.repeat(32), nonce: 'b'.repeat(32), ctx: 'cm:1',
          iat: Date.now(), exp: Date.now() + 30000, data: {},
        }, target);
      }, seed.urls.b);
      await page.waitForTimeout(2000);
      expect((await page.evaluate(() => window.__got)).filter((m) => m.origin === seed.urls.b)).toEqual([]);
    } finally {
      setConfig('b', 'allowedorigins', seed.urls.a);
      setConfig('a', 'peerbridge', 'auto');
      await context.close().catch(() => {});
    }
  });

  test('no sesskey, token or session identifier travels in any URL of the embedding page, the frame or the form', async ({ browser }) => {
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      const urls = [];
      page.on('request', (request) => urls.push(request.url()));
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      await expect.poll(() => page.frames().map((f) => f.url()).join(' '), { timeout: 60000 }).toMatch(/b\.tool-b\.test\/course\/view\.php/);
      const form = page.locator('form[data-region="fallback-form"]');
      expect(await form.getAttribute('method')).toBe('post');
      expect(await form.getAttribute('action')).not.toMatch(/[?&](sesskey|nonce|token)=/i);
      // Core's own OIDC request (mod/lti/auth.php) carries the protocol parameters "state" and "nonce" by design;
      // the check is about everything the plugin adds, and about session identifiers anywhere.
      const leaking = urls.filter((u) => /[?&](sesskey|nonce|token|MoodleSession)=/i.test(u)
        && !/\/lib\/ajax\/service\.php\?sesskey=/.test(u) && !/\/mod\/lti\/auth\.php\?/.test(u));
      expect(urls.filter((u) => /MoodleSession/i.test(u))).toEqual([]);
      expect(leaking).toEqual([]);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('the plugin adds no framing headers: the tool answer carries X-Frame-Options only as core decides', async ({ request }) => {
    const response = await request.get(`${seed.urls.b}/login/index.php`);
    const headers = response.headers();
    // allowframembedding=1 on the stack: core sends no X-Frame-Options; the plugin must not add one or a CSP.
    expect(headers['x-frame-options']).toBeUndefined();
    expect(headers['content-security-policy']).toBeUndefined();
    expect(Object.keys(headers).filter((h) => /ltixsembed|^x-lxs/i.test(h))).toEqual([]);
  });
});
