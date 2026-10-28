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
 * CE-01 / CE-02: session lifecycle of the embedded tool with REAL Chromium third-party cookie blocking,
 * the cookie matrix that explains it, and the Storage Access API recovery path.
 *
 * What is proven in real Chromium (the only browser installed here):
 *  - unpartitioned third-party cookies are refused in the A -> B frame, partitioned (CHIPS) ones are kept;
 *  - every cookie core sets for the LTI launch on B (session, OIDC state, cookie check) is partitioned,
 *    which is why initial launch, in-frame navigation, reload, expiry and re-launch work;
 *  - expiry of B's session is detected as a login page, and a new launch from A restores a session;
 *  - logout on A followed by a new launch needs a new sign-in on A first.
 *
 * What is NOT proven: Firefox, Safari, iOS. Chromium's behaviour is not evidence for them.
 *
 * The Storage Access API path (button in the tool frame, retry message, platform restarts the launch) is
 * exercised with an EMULATED browser: frame storage is cut and requestStorageAccess is a stub that
 * resolves or rejects. Real browser prompts cannot be driven here.
 */

const { test, expect, chromium } = require('@playwright/test');
const {
  stackAvailable, loadSeed, getUser, login, sql, setConfig, blockFrameStorage, newRealBlockingContext,
  snapshotStackOnce, restoreStack,
} = require('./support');

test.describe.configure({ mode: 'serial', retries: 1 });

const COURSE = /b\.tool-b\.test\/course\/view\.php/;
const NOTICE = '[data-region="local_ltixsembed-notice"][data-role="consumer"]';

test.describe('local_ltixsembed session lifecycle and storage access', () => {
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
    setConfig('a', 'handshaketimeout', 8);
    setConfig('b', 'allowedorigins', seed.urls.a);
    setConfig('b', 'providerenabled', 1);
    setConfig('b', 'providerltipages', 1);
    setConfig('b', 'storageaccess', 1);
  });

  test.afterAll(() => {
    if (snap) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  const toolFrame = (page) => page.frames().find((f) => f.url().startsWith(seed.urls.b));
  /** The user id the tool frame reports, retried while the frame is still navigating. */
  const frameUser = async (frame) => {
    for (let attempt = 0; attempt < 10; attempt++) {
      try {
        await frame.waitForLoadState('load');
        return await frame.evaluate(() => Number(window.M && window.M.cfg ? window.M.cfg.userId : 0));
      } catch (e) {
        await new Promise((resolve) => setTimeout(resolve, 500));
      }
    }
    throw new Error('the tool frame kept navigating');
  };

  async function launch(page) {
    await page.goto(activity.view_url);
    await expect.poll(() => page.frames().map((f) => f.url()).join(' '), { timeout: 60000 }).toMatch(COURSE);
    const frame = page.frames().find((f) => COURSE.test(f.url()));
    await frame.waitForLoadState('load');
    return frame;
  }

  test('cookie matrix: real blocking refuses unpartitioned and keeps partitioned cookies in the A -> B frame', async () => {
    const context = await newRealBlockingContext(chromium);
    test.skip(context === null, 'the stack helper for real cookie blocking is not installed');
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      const frame = await launch(page);

      const probe = await frame.evaluate(() => {
        document.cookie = 'lxsgapunpart=1; Secure; SameSite=None; Path=/';
        document.cookie = 'lxsgappart=1; Secure; SameSite=None; Partitioned; Path=/';
        return document.cookie;
      });
      expect(probe).not.toContain('lxsgapunpart=1');
      expect(probe).toContain('lxsgappart=1');

      // The cookies core set for the launch on B are all partitioned; none is stored unpartitioned.
      const cookies = (await context.cookies()).filter((c) => c.domain.endsWith('tool-b.test'));
      const names = cookies.map((c) => `${c.name.replace(/[a-f0-9]{8,}/, '<id>')}:${c.sameSite}:${c.partitionKey ? 'partitioned' : 'unpartitioned'}`);
      test.info().annotations.push({ type: 'cookies-on-B', description: names.join(', ') });
      expect(cookies.length).toBeGreaterThan(0);
      for (const c of cookies.filter((x) => /^(MoodleSession|lti1p3_state)/.test(x.name))) {
        expect(c.partitionKey, `${c.name} must be partitioned`).toBeTruthy();
        expect(c.sameSite).toBe('None');
        expect(c.secure).toBe(true);
      }
      expect(cookies.some((c) => /^MoodleSession/.test(c.name))).toBe(true);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('lifecycle under real blocking: launch, in-frame navigation, reload, expiry, re-launch, logout and relaunch', async () => {
    const context = await newRealBlockingContext(chromium);
    test.skip(context === null, 'the stack helper for real cookie blocking is not installed');
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));

      // 1. Initial launch.
      let frame = await launch(page);
      expect(await frameUser(frame)).toBeGreaterThan(2);

      // 2. Navigation inside the frame keeps the session.
      await frame.goto(`${seed.urls.b}/user/profile.php`);
      expect(await frameUser(frame)).toBeGreaterThan(2);

      // 3. Reload of the frame.
      await frame.evaluate(() => window.location.reload());
      expect(await frameUser(frame)).toBeGreaterThan(2);

      // 4. Expiry of B's session: the next request in the frame ends at a login page, not in the course.
      const aId = getUser(seed, 'studentA2').id;
      sql('b', `delete from mdl_sessions where userid in (select userid from mdl_auth_lti_linked_login where sub = '${Number(aId)}')`);
      await context.clearCookies({ domain: 'b.tool-b.test' });
      await frame.goto(`${seed.urls.b}/user/profile.php`);
      expect(await frameUser(frame)).toBe(0);

      // 5. A new launch from A restores a working session (fresh OIDC round trip with partitioned cookies).
      frame = await launch(page);
      expect(await frameUser(frame)).toBeGreaterThan(2);

      // 6. Logout on A, then a new launch needs a new sign-in on A; afterwards the launch works again.
      await page.goto(`${seed.urls.a}/my/`);
      const sesskey = await page.evaluate(() => window.M.cfg.sesskey);
      await page.goto(`${seed.urls.a}/login/logout.php?sesskey=${sesskey}`);
      await page.goto(activity.view_url, { waitUntil: 'domcontentloaded' });
      await page.waitForURL(/\/login\/index\.php/);
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      frame = await launch(page);
      expect(await frameUser(frame)).toBeGreaterThan(2);
    } finally {
      await context.close().catch(() => {});
    }
  });

  /**
   * An emulated browser: the tool frame has no cookie storage and the Storage Access API exists as a stub.
   *
   * @param {import('@playwright/test').BrowserContext} context The context.
   * @param {'grant'|'deny'} outcome What requestStorageAccess does.
   */
  async function emulateStorageAccess(context, outcome) {
    const calls = { n: 0 };
    await context.exposeFunction('__lxsSaaHit', () => {
      calls.n++;
    });
    await blockFrameStorage(context, seed.urls.b);
    await context.addInitScript(({ origin, result }) => {
      if (window.top !== window && window.location.origin === origin) {
        Document.prototype.hasStorageAccess = () => Promise.resolve(false);
        Document.prototype.requestStorageAccess = function requestStorageAccess() {
          window.__lxsSaaHit();
          return result === 'grant' ? Promise.resolve() : Promise.reject(new DOMException('denied', 'NotAllowedError'));
        };
      }
    }, { origin: seed.urls.b, result: outcome });
    return calls;
  }

  /** Count the launch requests the platform frame makes. */
  function countLaunches(page) {
    const counter = { n: 0 };
    page.on('request', (request) => {
      if (/\/mod\/lti\/launch\.php\?id=/.test(request.url()) && request.frame() !== page.mainFrame()) {
        counter.n++;
      }
    });
    return counter;
  }

  test('storage access: the button appears in the tool frame, a grant makes the platform restart the launch, bounded', async ({ browser }) => {
    const context = await browser.newContext();
    try {
      const calls = await emulateStorageAccess(context, 'grant');
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      const launches = countLaunches(page);
      await page.goto(activity.view_url);
      const notice = page.locator(NOTICE);
      await expect(notice).toHaveAttribute('data-lxs-current', 'needs_user_intervention', { timeout: 60000 });

      const frame = toolFrame(page);
      const button = frame.locator('[data-action="request-storage-access"]');
      await expect(button).toBeVisible({ timeout: 30000 });
      const before = launches.n;
      await button.click();
      // The "granted" message lives in the document the platform replaces at once, so it is not asserted here.
      await expect.poll(() => calls.n, { timeout: 10000 }).toBeGreaterThanOrEqual(1);
      // The platform restarts the launch after the validated retry message.
      await expect.poll(() => launches.n, { timeout: 30000 }).toBeGreaterThan(before);

      // The emulated browser still has no storage, so the state returns; the restart is bounded (2 per page load).
      for (let i = 0; i < 4; i++) {
        const f = toolFrame(page);
        const b = f.locator('[data-action="request-storage-access"]');
        if (await b.isVisible().catch(() => false)) {
          await b.click({ timeout: 5000 }).catch(() => {});
        }
        await page.waitForTimeout(1500);
      }
      await page.waitForTimeout(3000);
      expect(launches.n - before).toBeLessThanOrEqual(2);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('storage access: a denial shows the message, starts nothing and keeps the new-window option', async ({ browser }) => {
    const context = await browser.newContext();
    try {
      await emulateStorageAccess(context, 'deny');
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      const launches = countLaunches(page);
      await page.goto(activity.view_url);
      await expect(page.locator(NOTICE)).toHaveAttribute('data-lxs-current', 'needs_user_intervention', { timeout: 60000 });
      const frame = toolFrame(page);
      const before = launches.n;
      await frame.locator('[data-action="request-storage-access"]').click();
      await expect(frame.locator('[data-lxs-result="denied"]')).toBeVisible();
      await page.waitForTimeout(3000);
      expect(launches.n).toBe(before);
      await expect(page.locator(NOTICE).getByRole('button', { name: /new window|neuem Fenster/i })).toBeAttached();
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('storage access: a forged retry from the right frame with a forged nonce or from another window starts nothing', async ({ browser }) => {
    const context = await browser.newContext();
    try {
      await emulateStorageAccess(context, 'deny');
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      const launches = countLaunches(page);
      await page.goto(activity.view_url);
      await expect(page.locator(NOTICE)).toHaveAttribute('data-lxs-current', 'needs_user_intervention', { timeout: 60000 });
      const before = launches.n;
      const forged = (nonce) => ({
        lxs: 1, type: 'retry', mid: 'e'.repeat(32), nonce, ctx: `cm:${activity.cmid}`,
        iat: Date.now(), exp: Date.now() + 30000, data: { reason: 'storage_access_granted' },
      });
      await toolFrame(page).evaluate(({ m, target }) => window.parent.postMessage(m, target), { m: forged('f'.repeat(32)), target: seed.urls.a });
      await page.evaluate((m) => window.postMessage(m, '*'), forged('f'.repeat(32)));
      await page.waitForTimeout(3000);
      expect(launches.n).toBe(before);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('real Chromium: the Storage Access API exists in the frame and a call without a user gesture is refused', async () => {
    const context = await newRealBlockingContext(chromium);
    test.skip(context === null, 'the stack helper for real cookie blocking is not installed');
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      const frame = await launch(page);
      const result = await frame.evaluate(async () => {
        const api = typeof document.requestStorageAccess === 'function' && typeof document.hasStorageAccess === 'function';
        let has = null;
        let requested = 'n/a';
        if (api) {
          has = await document.hasStorageAccess();
          requested = await document.requestStorageAccess().then(() => 'resolved', (e) => e.name);
        }
        return { api, has, requested };
      });
      test.info().annotations.push({ type: 'chromium-saa', description: JSON.stringify(result) });
      expect(result.api).toBe(true);
      // Without a user gesture the browser must not grant anything (recorded, not a promise for other browsers).
      expect(result.requested).not.toBe('resolved');
    } finally {
      await context.close().catch(() => {});
    }
  });
});
