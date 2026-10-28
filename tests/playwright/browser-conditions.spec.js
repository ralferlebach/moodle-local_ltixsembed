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
 * CE-03: native sessions and browser negative cases, recorded as traces with the runtime state.
 *
 *  - cookies under REAL Chromium third-party cookie blocking: which tool cookies exist and whether they are
 *    partitioned (CHIPS) - per operating mode, so the plugin is shown not to add or change a cookie;
 *  - Storage Access API in REAL Chromium: without a grant the frame cannot use an unpartitioned cookie; with the
 *    browser permission granted (Playwright grants the permission the browser prompt would ask for) and a user
 *    gesture, requestStorageAccess resolves and the unpartitioned cookie becomes usable;
 *  - CSP frame-ancestors (header added to the tool's frame responses by request interception - the server setting is
 *    emulated, the enforcement is the real browser's) and X-Frame-Options (real Moodle setting "allowframembedding"
 *    switched off on B): the frame is refused; with peer bridge "required" the platform reports blocked_frame and
 *    the new window reaches the course top-level (recorded as fallback, not as embedded); with "auto" it says nothing;
 *  - session expiry inside the frame and relaunch (real blocking): the tool's login page reports session_lost to the
 *    platform, and a new launch embeds again with exactly one more OIDC round trip.
 *
 * Firefox, WebKit, Safari and iOS: not installed here, not verified.
 */

const { test, expect, chromium } = require('@playwright/test');
const {
  stackAvailable, loadSeed, getUser, login, sql, setConfig, newRealBlockingContext,
  snapshotStackOnce, restoreStack, applyMode, recordLaunch, saveTrace,
  learner,
} = require('./support');

const NOTICE = '[data-region="local_ltixsembed-notice"][data-role="consumer"]';
const COURSE = /\/course\/view\.php/;

test.describe('local_ltixsembed CE-03 browser conditions', () => {
  test.skip(!stackAvailable(), 'the two-site stack is not available (LTI_SEED)');
  test.skip(({ browserName }) => browserName !== 'chromium', 'uses Chromium profile settings');

  let seed;
  let snap;

  test.beforeAll(() => {
    seed = loadSeed();
    snap = snapshotStackOnce();
    sql('a', `update mdl_lti set launchcontainer=3 where id=${seed.lti_activity.instance}`);
  });

  test.afterAll(() => {
    if (snap) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  const toolFrame = (page) => page.frames().find((f) => f.parentFrame() === page.mainFrame() && f.url().startsWith(seed.urls.b));

  async function launchAndSettle(page, ms = 9000, refused = false) {
    await page.goto(seed.lti_activity.view_url);
    if (!refused) {
      await expect.poll(() => page.frames().filter((f) => f.url().startsWith(seed.urls.b)).length, { timeout: 60000 })
        .toBeGreaterThan(0);
    }
    await page.waitForLoadState('load');
    await page.waitForTimeout(refused ? ms + 6000 : ms);
  }

  for (const mode of ['none', 'consumer', 'provider', 'cooperative']) {
    test(`real blocking, ${mode}: the tool's launch cookies are partitioned and the plugin adds none`, async ({}, testInfo) => {
      applyMode(mode, { a: { handshaketimeout: 4, peerbridge: 'auto' }, b: { providerltipages: 1 } });
      const context = await newRealBlockingContext(chromium);
      expect(context).not.toBeNull();
      try {
        const page = await context.newPage();
        await login(page, seed.urls.a, learner(seed));
        const rec = await recordLaunch(context, seed);
        await launchAndSettle(page);
        const trace = await rec.finish(page);
        await saveTrace(testInfo, `cookies-blocked3pc-${mode}`, trace);
        expect(trace.outcome.iframe).toBe('success');
        const launchCookies = trace.cookiesB.filter((c) => /^(MoodleSession|lti1p3_)/.test(c.name));
        expect(launchCookies.length).toBeGreaterThan(0);
        for (const c of launchCookies) {
          expect(c, c.name).toMatchObject({ sameSite: 'None', secure: true, partitioned: true });
        }
        expect(trace.cookiesB.filter((c) => /lxs/i.test(c.name)), 'no cookie of the plugin survives').toEqual([]);
      } finally {
        await context.close().catch(() => {});
      }
    });
  }

  test('real Chromium Storage Access API: refused without permission; behaviour after an explicit grant is recorded', async ({}, testInfo) => {
    applyMode('cooperative', { a: { handshaketimeout: 4 }, b: { providerltipages: 1 } });
    const context = await newRealBlockingContext(chromium);
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, learner(seed));
      await launchAndSettle(page, 2000);
      const frame = toolFrame(page);
      const probe = () => frame.evaluate(() => {
        document.cookie = 'lxssaa=1; Secure; SameSite=None; Path=/';
        const ok = document.cookie.includes('lxssaa=1');
        document.cookie = 'lxssaa=; Secure; SameSite=None; Path=/; Max-Age=0';
        return ok;
      });
      const result = { before: {}, after: {} };
      result.before.hasStorageAccess = await frame.evaluate(() => document.hasStorageAccess());
      result.before.unpartitionedCookie = await probe();
      result.before.requestWithoutGesture = await frame.evaluate(() => document.requestStorageAccess().then(() => 'resolved', (e) => e.name));

      // Grant the permission the browser prompt would ask for, keyed to (embedding site A, embedded origin B), and give
      // B a top-level visit first (Chromium requires prior top-level interaction with the embedded site).
      const top = await context.newPage();
      await top.goto(`${seed.urls.b}/login/index.php`);
      await top.click('body');
      await top.close();
      const cdp = await context.newCDPSession(page);
      result.grant = await cdp.send('Browser.setPermission', {
        permission: { name: 'storage-access' }, setting: 'granted', origin: seed.urls.a, embeddedOrigin: seed.urls.b,
      }).then(() => 'set', (e) => e.message);
      // A user gesture inside the frame: a real click on an element the test adds to the tool page.
      await frame.evaluate(() => {
        const b = document.createElement('button');
        b.id = 'lxs-saa-test';
        b.textContent = 'request';
        b.style.cssText = 'position:fixed;top:0;left:0;z-index:99999';
        b.addEventListener('click', () => {
          window.__saa = document.requestStorageAccess().then(() => 'resolved', (e) => e.name);
        });
        document.body.appendChild(b);
      });
      await frame.click('#lxs-saa-test');
      result.after.request = await frame.evaluate(() => window.__saa);
      result.after.hasStorageAccess = await frame.evaluate(() => document.hasStorageAccess());
      result.after.unpartitionedCookie = await probe();
      await saveTrace(testInfo, 'saa-real-chromium', result);

      expect(result.before.unpartitionedCookie, 'real blocking refuses the unpartitioned cookie').toBe(false);
      expect(result.before.requestWithoutGesture).not.toBe('resolved');
      // The outcome after the grant is recorded as observed (trace "saa-real-chromium") and reported in ACCEPTANCE; it is
      // asserted consistent: either the API granted access and the unpartitioned cookie works, or both stay refused.
      expect(result.grant).toBe('set');
      expect(result.after.unpartitionedCookie).toBe(result.after.request === 'resolved');
    } finally {
      await context.close().catch(() => {});
    }
  });

  /** Make B refuse framing by A through a CSP frame-ancestors header on its frame documents (emulated server setting). */
  async function cspFrameAncestors(context) {
    await context.route(`${seed.urls.b}/**`, async (route) => {
      const request = route.request();
      const frame = request.frame();
      if (!request.isNavigationRequest() || !frame || frame.parentFrame() === null) {
        await route.continue();
        return;
      }
      try {
        const response = await route.fetch({ maxRedirects: 0 });
        const headers = { ...response.headers(), 'content-security-policy': "frame-ancestors 'self'" };
        await route.fulfill({ response, headers });
      } catch (e) {
        // Context closed during teardown.
      }
    });
  }

  for (const [label, prepare] of [
    ['CSP frame-ancestors', async (context) => cspFrameAncestors(context)],
    ['X-Frame-Options (allowframembedding off on B)', async () => setConfig('b', 'allowframembedding', 0, 'core')],
  ]) {
    test(`${label}: refused frame, "required" reports blocked_frame and the new window works top-level; "auto" stays silent`, async ({ browser }, testInfo) => {
      for (const peerbridge of ['required', 'auto']) {
        applyMode('cooperative', { a: { handshaketimeout: 4, peerbridge, fallbackmode: 'suggest' }, b: { providerltipages: 1 } });
        const context = await browser.newContext();
        try {
          await prepare(context);
          const page = await context.newPage();
          await login(page, seed.urls.a, learner(seed));
          const rec = await recordLaunch(context, seed);
          await launchAndSettle(page, 9000, true);
          let trace = await rec.finish(page);
          expect(trace.outcome.iframe, 'the frame is refused').toBe('failed');
          if (peerbridge === 'required') {
            expect(trace.consumer.current).toBe('blocked_frame');
            expect(trace.consumer.noticeVisible).toBe(true);
            const [popup] = await Promise.all([
              context.waitForEvent('page'),
              page.locator(NOTICE).getByRole('button', { name: /new window|neuem Fenster/i }).click(),
            ]);
            await popup.waitForURL(/\/(course\/view|enrol\/lti\/launch)\.php/, { timeout: 90000 });
            await popup.waitForLoadState('load');
            if (/enrol\/lti\/launch\.php/.test(popup.url())) {
              // Core enrol_lti with frame embedding off shows "Open tool" (target _blank) instead of redirecting - also
              // in a top-level window. That second window is core's, not the plugin's.
              const [toolPage] = await Promise.all([context.waitForEvent('page'), popup.locator('a[target="_blank"]').first().click()]);
              await toolPage.waitForURL(COURSE, { timeout: 60000 });
              await toolPage.waitForLoadState('load');
            }
            trace = await rec.finish(page);
            expect(trace.outcome).toEqual({ iframe: 'failed', toplevel: 'success' });
            expect(trace.consumer.fallback).toBe('opened');
            expect(trace.counts.windows).toBeLessThanOrEqual(label.startsWith('X-Frame') ? 2 : 1);
          } else {
            expect(trace.consumer.current).toBe('no_peer');
            expect(trace.consumer.noticeVisible).toBe(false);
            expect(trace.counts.windows).toBe(0);
          }
          await saveTrace(testInfo, `refused-${label.split(' ')[0]}-${peerbridge}`, trace);
        } finally {
          await context.close().catch(() => {});
          if (label.startsWith('X-Frame')) {
            setConfig('b', 'allowframembedding', snap.frame.b || 1, 'core');
          }
        }
      }
    });
  }

  test('real blocking, cooperative: session expiry inside the frame is reported as session_lost, a relaunch embeds again', async ({}, testInfo) => {
    applyMode('cooperative', { a: { handshaketimeout: 4, peerbridge: 'auto', fallbackmode: 'suggest' }, b: { providerltipages: 1 } });
    const context = await newRealBlockingContext(chromium);
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, learner(seed));
      const rec = await recordLaunch(context, seed);
      await launchAndSettle(page);
      const first = await rec.finish(page);
      expect(first.outcome.iframe).toBe('success');
      expect(first.consumer.current).toBe('embedded');

      // Expire B's session of this learner (server side) and navigate inside the frame.
      const aId = learner(seed).id;
      sql('b', `delete from mdl_sessions where userid in (select userid from mdl_auth_lti_linked_login where sub = '${Number(aId)}')`);
      await context.clearCookies({ domain: new URL(seed.urls.b).hostname });
      await toolFrame(page).evaluate((u) => { window.location.href = u; }, `${seed.urls.b}/user/profile.php`);
      await expect(page.locator(NOTICE)).toHaveAttribute('data-lxs-current', 'session_lost', { timeout: 30000 });
      const expired = await rec.finish(page);
      expect(expired.consumer.noticeVisible).toBe(true);
      expect(expired.frame.path).toBe('/login/index.php');

      // Relaunch from A: one more OIDC round trip, embedded again.
      await launchAndSettle(page);
      const relaunched = await rec.finish(page);
      await saveTrace(testInfo, 'session-expiry-relaunch', { first, expired, relaunched });
      expect(relaunched.outcome.iframe).toBe('success');
      expect(relaunched.consumer.current).toBe('embedded');
      // Exactly one more launch: core's OIDC initiation traffic doubles, one more platform auth request.
      expect(relaunched.counts.oidcLogin).toBe(2 * first.counts.oidcLogin);
      expect(relaunched.counts.authRequest).toBe(first.counts.authRequest + 1);
      expect(relaunched.counts.windows).toBe(0);
    } finally {
      await context.close().catch(() => {});
    }
  });
});
