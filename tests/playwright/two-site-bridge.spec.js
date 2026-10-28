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
 * Two-site suite: Moodle A (platform) embeds Moodle B (tool) in an iframe.
 *
 * Third-party cookies are blocked for real (Chromium profile setting, newRealBlockingContext) when the
 * stack helper exists; otherwise storage is EMULATED (blockFrameStorage). The projects firefox and webkit
 * run the same specs where those browsers are installed (not verified locally).
 *
 * What is asserted:
 *  - the tool-side bridge detects the blocked storage and reports it, the platform shows the notice;
 *  - the new-window fallback starts a fresh trusted launch and the learner reaches the course on B;
 *  - forged messages (wrong origin, wrong source, forged nonce, opaque origin) change nothing;
 *  - reload and back navigation keep the behaviour;
 *  - without blocking, the embedded launch works and the bridge reports "embedded".
 */

const { test, expect } = require('@playwright/test');
const {
  stackAvailable, loadSeed, getUser, login, sql, setConfig, blockFrameStorage, newRealBlockingContext,
  snapshotStackOnce, restoreStack,
} = require('./support');
const { chromium } = require('@playwright/test');

// Launching the same learner on B several times in a row intermittently ends in a DML write error on B
// (observed in 2 of 4 full runs, not reproducible in isolation); one retry per test absorbs it.
test.describe.configure({ mode: 'serial', retries: 1 });

const PROBLEM_STATES = /^(storage_unavailable|session_lost|blocked_frame|needs_user_intervention)$/;

test.describe('local_ltixsembed two-site embedding', () => {
  test.skip(!stackAvailable(), 'the two-site stack is not available (LTI_SEED)');

  let seed;
  let activity;
  let snap;

  test.beforeAll(() => {
    seed = loadSeed();
    activity = seed.lti_activity;
    snap = snapshotStackOnce();
    sql('a', `update mdl_lti set launchcontainer=3 where id=${activity.instance}`); // Embedded, no blocks.
    setConfig('a', 'allowedorigins', seed.urls.b);
    setConfig('a', 'consumerenabled', 1);
    setConfig('a', 'providerenabled', 1);
    setConfig('a', 'peerbridge', 'auto');
    setConfig('a', 'handshaketimeout', 8);
    setConfig('b', 'allowedorigins', seed.urls.a);
    setConfig('b', 'providerenabled', 1);
    setConfig('b', 'providerltipages', 1);
  });

  test.afterAll(() => {
    if (snap) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  /** Open the embedding page as a student with the tool's frame storage blocked. */
  async function openBlocked(browser) {
    // Emulation of a browser that gives the frame no cookie storage at all (Safari, Firefox strict).
    // Chromium's own blocking cannot stand in for it: it still allows partitioned (CHIPS) cookies, which
    // core sets for LTI launches, so the embedded launch keeps working there (see the next test).
    const context = await browser.newContext();
    await blockFrameStorage(context, seed.urls.b);
    const page = await context.newPage();
    await login(page, seed.urls.a, getUser(seed, 'studentA2'));
    await page.goto(activity.view_url);
    const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
    await expect(notice).toHaveAttribute('data-lxs-current', PROBLEM_STATES, { timeout: 60000 });
    return { context, page, notice };
  }

  test('blocked storage in the frame is detected, explained, and the new window reaches the course on B', async ({ browser }) => {
    const { context, page, notice } = await openBlocked(browser);
    try {
      const state = await notice.getAttribute('data-lxs-current');
      test.info().annotations.push({ type: 'detected-state', description: state });
      await expect(notice.locator(`[data-lxs-state="${state}"]`)).toBeVisible();
      await expect(notice).toContainText(/Nothing about you|nothing about you|Nichts über Sie/i);

      const [popup] = await Promise.all([
        context.waitForEvent('page'),
        notice.getByRole('button', { name: /new window|neuem Fenster/i }).click(),
      ]);
      await popup.waitForURL(/b\.tool-b\.test\/course\/view\.php/, { timeout: 90000 });
      await expect(popup.locator('body')).toContainText('Provider course');
      // The popup is a top-level window of B: no frame, a working session.
      const userId = await popup.evaluate(() => window.M.cfg.userId);
      expect(Number(userId)).toBeGreaterThan(2);
      // No secret travelled in a URL.
      for (const p of context.pages()) {
        expect(p.url()).not.toMatch(/[?&](sesskey|token|nonce|MoodleSession)=/i);
      }
      const logged = sql('a', "select count(*) from mdl_logstore_standard_log where eventname like '%fallback_launched'");
      expect(Number(logged)).toBeGreaterThanOrEqual(1);
    } finally {
      await context.close();
    }
  });

  test('with real Chromium third-party cookie blocking the embedded launch still works through the partitioned session cookie', async () => {
    const context = await newRealBlockingContext(chromium);
    test.skip(context === null, 'the stack helper for real cookie blocking is not installed');
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      await expect.poll(() => page.frames().map((f) => f.url()).join(' '), { timeout: 60000 })
        .toMatch(/b\.tool-b\.test\/course\/view\.php/);
      const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
      // Recorded finding: the browser blocks unpartitioned third-party cookies, core's CHIPS cookie survives.
      await expect(notice).toHaveAttribute('data-lxs-current', 'embedded', { timeout: 30000 });
    } finally {
      await context.close();
    }
  });

  test('forged postMessages are ignored while the genuine state stays in place', async ({ browser }) => {
    const { context, page, notice } = await openBlocked(browser);
    try {
      const genuine = await notice.getAttribute('data-lxs-current');
      const forged = (nonce) => ({
        lxs: 1, type: 'state', mid: 'a'.repeat(32), nonce, ctx: `cm:${activity.cmid}`,
        iat: Date.now(), exp: Date.now() + 30000, data: { state: 'embedded' },
      });

      // 1. From the platform's own window: origin A is not on the allow-list, and the source is not the frame.
      await page.evaluate((m) => window.postMessage(m, '*'), forged('b'.repeat(32)));
      // 2. From an opaque-origin frame (data: URL).
      await page.evaluate((m) => {
        const frame = document.createElement('iframe');
        frame.src = `data:text/html,<script>parent.postMessage(${JSON.stringify(m)},'*')<\/script>`;
        document.body.appendChild(frame);
      }, forged('c'.repeat(32)));
      // 3. From the real tool frame (right origin, right source) but with a forged nonce.
      const toolFrame = page.frames().find((f) => f.url().startsWith(seed.urls.b));
      expect(toolFrame, 'the tool frame must be present').toBeTruthy();
      await toolFrame.evaluate(({ m, target }) => window.parent.postMessage(m, target), { m: forged('d'.repeat(32)), target: seed.urls.a });
      // 4. Unknown type and malformed envelope from the right place.
      await toolFrame.evaluate(({ target }) => window.parent.postMessage({ lxs: 1, type: 'launch', url: 'https://evil.example/' }, target), { target: seed.urls.a });
      await toolFrame.evaluate(({ target }) => window.parent.postMessage('embedded', target), { target: seed.urls.a });

      await page.waitForTimeout(1500);
      await expect(notice).toHaveAttribute('data-lxs-current', genuine);
      await expect(page.locator('iframe#contentframe')).toHaveAttribute('src', /\/mod\/lti\/launch\.php\?id=/);
      expect(page.url()).toBe(activity.view_url);
    } finally {
      await context.close();
    }
  });

  test('reload and back navigation keep the detection and the fallback working', async ({ browser }) => {
    const { context, page, notice } = await openBlocked(browser);
    try {
      await page.reload();
      await expect(notice).toHaveAttribute('data-lxs-current', PROBLEM_STATES, { timeout: 60000 });

      await page.goto(`${seed.urls.a}/my/`);
      await page.goBack();
      await expect(page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]'))
        .toHaveAttribute('data-lxs-current', PROBLEM_STATES, { timeout: 60000 });

      const fresh = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
      const [popup] = await Promise.all([
        context.waitForEvent('page'),
        fresh.getByRole('button', { name: /new window|neuem Fenster/i }).click(),
      ]);
      await popup.waitForURL(/b\.tool-b\.test\/course\/view\.php/, { timeout: 90000 });
    } finally {
      await context.close();
    }
  });

  test('a session that expires on A does not leave a usable fallback behind', async ({ browser }) => {
    const { context, page, notice } = await openBlocked(browser);
    try {
      const form = notice.locator('form[data-region="fallback-form"]');
      const action = new URL(await form.getAttribute('action'), seed.urls.a).toString();
      const body = {
        cmid: String(activity.cmid),
        nonce: await form.locator('input[name="nonce"]').inputValue(),
        sesskey: await page.evaluate(() => window.M.cfg.sesskey),
        reason: 'manual',
      };
      await context.clearCookies({ domain: 'a.platform-a.test' });
      const response = await page.request.post(action, { form: body, maxRedirects: 0 });
      // Without a session the request ends at the login page; it never reaches the launch.
      const location = response.headers().location || '';
      expect(location).not.toMatch(/mod\/lti\/launch\.php/);
    } finally {
      await context.close();
    }
  });

  test('without blocking the launch works inside the frame and the bridge reports "embedded"', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      await expect.poll(() => page.frames().map((f) => f.url()).join(' '), { timeout: 60000 })
        .toMatch(/b\.tool-b\.test\/course\/view\.php/);
      const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
      await expect(notice).toHaveAttribute('data-lxs-current', 'embedded', { timeout: 30000 });
      await expect(notice.locator('[data-region="state-message"]')).toBeHidden();
    } finally {
      await context.close();
    }
  });
});
