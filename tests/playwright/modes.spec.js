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
 * CE-02: three independent operating modes on the real two-site stack (A = Moodle 4.5 platform, B = Moodle 5.3 tool,
 * genuinely cross-site), switched through the plugin's own role switches - never by uninstalling:
 *
 *   none         plugin inactive on both sites (baseline: what core does alone)
 *   consumer     active on A only, B inactive (unchanged)
 *   provider     active on B only, A inactive (unchanged)
 *   cooperative  active on both
 *
 * Each mode runs under three browser conditions:
 *   default      Chromium as shipped (third-party cookies allowed)
 *   blocked3pc   REAL Chromium third-party cookie blocking (profile setting; partitioned cookies still allowed)
 *   nostorage    EMULATED browser without any frame storage (stands in for Safari / Firefox strict; not real)
 *
 * Every run writes a trace (support.recordLaunch): OIDC login initiations, platform auth requests, tool launch
 * POSTs, frame redirects, OIDC state cookies, tool session cookies, windows opened, every delivered postMessage, the
 * runtime state of both notices and the outcome - an embedded success and a top-level fallback are recorded
 * separately and a fallback is never counted as iframe success.
 *
 * The guarantee asserted: with both sides active the result is never worse than the successful one-sided behaviour
 * and never adds a redirect, OIDC state, cookie or window that the one-sided runs did not need.
 *
 * Firefox / WebKit run the same file only where installed (LXS_ALL_BROWSERS=1); here: not verified.
 */

const { test, expect, chromium, firefox } = require('@playwright/test');
const {
  stackAvailable, loadSeed, getUser, login, sql, setConfig, blockFrameStorage, newRealBlockingContext,
  snapshotStackOnce, restoreStack, applyMode, recordLaunch, saveTrace, putResult, getResult,
  learner,
} = require('./support');

const MODE_NAMES = ['none', 'consumer', 'provider', 'cooperative'];
const CONDITIONS = ['default', 'blocked3pc', 'nostorage'];
const COUNTED = ['oidcLogin', 'authRequest', 'toolLaunch', 'frameRedirects', 'stateCookies', 'toolSessionCookies', 'windows'];

test.describe('local_ltixsembed CE-02 operating modes', () => {
  test.skip(!stackAvailable(), 'the two-site stack is not available (LTI_SEED)');

  let seed;
  let snap;

  test.beforeAll(() => {
    seed = loadSeed();
    snap = snapshotStackOnce();
    sql('a', `update mdl_lti set launchcontainer=3 where id=${seed.lti_activity.instance}`); // Embedded, no blocks.
  });

  test.afterAll(async ({}, testInfo) => {
    if (snap) {
      const left = restoreStack(snap);
      await testInfo.attach('restore', { body: JSON.stringify({ remainingDifferences: left }), contentType: 'application/json' });
      expect(left, 'the stack configuration is restored').toEqual([]);
    }
  });

  /**
   * A context for a browser condition. "blocked3pc" is the browser's OWN third-party cookie switch: Chromium's profile
   * setting (partitioned cookies stay allowed) or Firefox's network.cookie.cookieBehavior=1 (reject all third-party
   * cookies - a real browser without frame storage). Playwright WebKit has no such switch.
   */
  async function contextFor(condition, browser) {
    const name = browser.browserType().name();
    if (condition === 'blocked3pc' && name === 'chromium') {
      const ctx = await newRealBlockingContext(chromium);
      expect(ctx, 'the stack helper for real third-party cookie blocking must exist').not.toBeNull();
      return ctx;
    }
    if (condition === 'blocked3pc' && name === 'firefox') {
      const own = await firefox.launch({ firefoxUserPrefs: { 'network.cookie.cookieBehavior': 1, 'network.proxy.type': 0 } });
      const ctx = await own.newContext({ ignoreHTTPSErrors: true });
      ctx.on('close', () => own.close().catch(() => {}));
      return ctx;
    }
    const ctx = await browser.newContext(name === 'firefox' ? { ignoreHTTPSErrors: true } : {});
    // WebKit blocks third-party cookies by default: it IS a real no-frame-storage browser, the emulation is not used
    // (route-based fulfilment of frame navigations also fails in WebKit).
    if (condition === 'nostorage' && name !== 'webkit') {
      await blockFrameStorage(ctx, seed.urls.b);
    }
    return ctx;
  }

  /**
   * One launch in one mode and condition; returns the trace.
   *
   * @param {Object} browser Playwright browser.
   * @param {string} mode Mode.
   * @param {string} condition Condition.
   * @param {Object} [options] {click: true to use the new-window option after settling, extra: settings}.
   */
  async function run(browser, mode, condition, options = {}) {
    test.skip(test.info().project.name === 'mobile-safari', 'mobile: core launches without an iframe (own test below)');
    test.skip(condition === 'blocked3pc' && browser.browserType().name() === 'webkit',
      'Playwright WebKit offers no third-party cookie switch: not verified');
    applyMode(mode, {
      a: { handshaketimeout: 4, fallbackmode: 'suggest', peerbridge: 'auto', ...(options.extra && options.extra.a) },
      b: { providerltipages: 1, storageaccess: 1, ...(options.extra && options.extra.b) },
    });
    // Harness guard (WebKit, Linux): the iframe request to the PLATFORM'S OWN /mod/lti/launch.php intermittently fails
    // with "WebKit encountered an internal error" before any LTI or plugin step; the frame then loads A's login page as
    // a guest, which replaces A's session cookie. Observed 2x in about 30 WebKit test launches, 0x in 16 isolated
    // launches with and without the plugin. Exactly that failure - nothing else - leads to one retry in a fresh context;
    // the retry is recorded in the trace.
    let harnessRetry = null;
    for (let attempt = 1; ; attempt++) {
      const context = await contextFor(condition, browser);
      let ownLaunchFailure = '';
      context.on('requestfailed', (r) => {
        if (r.url().startsWith(`${seed.urls.a}/mod/lti/launch.php`) && r.failure()) {
          ownLaunchFailure = r.failure().errorText;
        }
      });
      try {
        const page = await context.newPage();
        await login(page, seed.urls.a, learner(seed));
        const rec = await recordLaunch(context, seed);
        await page.goto(seed.lti_activity.view_url);
        // Settle: the frame reaches B's course, or a problem page; then the handshake timeout passes.
        await expect.poll(() => (ownLaunchFailure ? 'own-launch-failed'
          : String(page.frames().filter((f) => f.url().startsWith(seed.urls.b)).length > 0)), { timeout: 60000 })
          .toMatch(/^(true|own-launch-failed)$/);
        if (ownLaunchFailure) {
          expect(attempt, `the platform's own launch request failed twice: ${ownLaunchFailure}`).toBe(1);
          expect(ownLaunchFailure).toMatch(/WebKit encountered an internal error/);
          harnessRetry = ownLaunchFailure;
          continue;
        }
        await page.waitForLoadState('load');
        await page.waitForTimeout(9000);
        let trace = await rec.finish(page);
        if (options.click) {
          const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
          const details = notice.locator('[data-region="manual-fallback"]');
          if (!(await details.evaluate((d) => d.open))) {
            await notice.locator('summary').click();
          }
          const [popup] = await Promise.all([
            context.waitForEvent('page'),
            notice.getByRole('button', { name: /new window|neuem Fenster/i }).click(),
          ]);
          await popup.waitForURL(/\/(course|mod\/[a-z0-9_]+)\/view\.php/, { timeout: 90000 }).catch(() => {});
          await popup.waitForLoadState('load').catch(() => {});
          trace = await rec.finish(page);
        }
        return { ...trace, harnessRetry };
      } finally {
        await context.close().catch(() => {});
      }
    }
  }

  for (const condition of CONDITIONS) {
    for (const mode of MODE_NAMES) {
      test(`${condition} / ${mode}`, async ({ browser }, testInfo) => {
        test.skip(condition === 'nostorage' && testInfo.project.name === 'webkit',
          'WebKit: the default condition already is native no-frame-storage');
        const trace = await run(browser, mode, condition);
        putResult(`${testInfo.project.name}/${condition}/${mode}`, { counts: trace.counts, outcome: trace.outcome });
        await saveTrace(testInfo, `modes-${condition}-${mode}`, trace);

        // Invariants of every mode and condition.
        expect(trace.counts.windows, 'nothing opens a window by itself (suggest mode)').toBe(0);
        if (condition !== 'nostorage' && testInfo.project.name === 'chromium') {
          // Chromium facts of the core flow (Firefox makes two auth requests, WebKit hides Set-Cookie from the trace):
          // other browsers are held to their own plugin-free baseline below.
          expect(trace.counts.authRequest, 'exactly one platform authentication request').toBe(1);
          expect(trace.counts.stateCookies, 'exactly one OIDC state cookie').toBe(1);
        }
        if (mode !== 'none') {
          // The launch itself is what core does without the plugin (baseline "none" of the same condition, run first):
          // core's own OIDC initiation (two requests to B's login.php), redirects, state cookie and launch POST.
          const baseline = getResult(`${testInfo.project.name}/${condition}/none`);
          expect(baseline, 'the baseline run of this condition exists').toBeTruthy();
          for (const key of ['oidcLogin', 'authRequest', 'toolLaunch', 'frameRedirects', 'stateCookies']) {
            expect(trace.counts[key], `${key} equals the plugin-free baseline`).toBe(baseline.counts[key]);
          }
          expect(trace.counts.toolSessionCookies).toBeLessThanOrEqual(baseline.counts.toolSessionCookies);
        }
        const foreign = trace.messages.filter((m) => ![seed.urls.a, seed.urls.b].includes(m.receiver)
          || ![seed.urls.a, seed.urls.b].includes(m.sender));
        expect(foreign, 'no message reaches or comes from a third origin').toEqual([]);
        if (mode === 'none' || mode === 'provider') {
          expect(trace.consumer, 'A inactive: no platform notice').toBeNull();
          expect(trace.messages.filter((m) => m.receiver === seed.urls.b && m.kind.startsWith('lxs:')),
            'A inactive: the tool hears nothing from A').toEqual([]);
        }
        if (mode === 'none' || mode === 'consumer') {
          expect(trace.frame && trace.frame.provider, 'B inactive: no tool notice').toBeFalsy();
          expect(trace.messages.filter((m) => m.receiver === seed.urls.a && m.kind.startsWith('lxs:')),
            'B inactive: the platform hears nothing from B').toEqual([]);
        }

        // Chromium: default and real blocking keep storage (CHIPS), nostorage removes it. Other browsers: their own
        // plugin-free baseline of the same condition decides (recorded observation, not assumed).
        const own = getResult(`${testInfo.project.name}/${condition}/none`);
        const storageWorks = testInfo.project.name === 'chromium' ? condition !== 'nostorage'
          : (own ? own.outcome.iframe === 'success' : trace.outcome.iframe === 'success');
        if (storageWorks) {
          // Storage works (cookies allowed, or partitioned cookies under real blocking): every mode embeds.
          expect(trace.outcome.iframe, 'embedded launch works').toBe('success');
          expect(trace.counts.toolLaunch).toBe(1);
          if (trace.consumer) {
            expect(trace.consumer.noticeVisible, 'no warning on a working launch').toBe(false);
            expect(trace.consumer.fallback).toBe('none');
            expect(trace.consumer.current).toBe(mode === 'cooperative' ? 'embedded' : 'no_peer');
          }
          if (trace.frame && trace.frame.provider) {
            expect(trace.frame.provider.noticeVisible).toBe(false);
            expect(trace.frame.provider.channel).toBe(mode === 'cooperative' ? 'open' : 'none');
          }
        } else {
          // No frame storage at all: the embedded launch cannot keep a session in any mode (CE-G1/G2).
          expect(trace.outcome.iframe).toBe('failed');
          if (mode === 'consumer') {
            // Consumer-only cannot see inside the tool: silence is not called a problem; the manual option stays.
            expect(trace.consumer.current).toBe('no_peer');
            expect(trace.consumer.noticeVisible).toBe(false);
          }
          if (mode === 'provider' || mode === 'cooperative') {
            expect(trace.frame.provider.noticeVisible, 'the tool explains the problem in the frame').toBe(true);
            expect(trace.frame.provider.channel).toBe(mode === 'cooperative' ? 'open' : 'none');
          }
          if (mode === 'cooperative') {
            expect(trace.consumer.current).toMatch(/^(storage_unavailable|needs_user_intervention|session_lost)$/);
            expect(trace.consumer.noticeVisible, 'the platform explains the reason').toBe(true);
            expect(trace.consumer.fallback).toBe('suggested');
            expect(trace.consumer.peer).toBe('bridge');
          }
        }
      });
    }

    test(`${condition} / never worse together: cooperative is bounded by the one-sided runs`, async ({ browserName }, testInfo) => {
      test.skip(testInfo.project.name === 'mobile-safari', 'mobile: no iframe');
      test.skip(condition === 'nostorage' && browserName === 'webkit', 'WebKit: covered by the default condition');
      test.skip(condition === 'blocked3pc' && browserName === 'webkit', 'no third-party cookie switch in Playwright WebKit');
      const coop = getResult(`${testInfo.project.name}/${condition}/cooperative`);
      const sides = ['none', 'consumer', 'provider'].map((m) => getResult(`${testInfo.project.name}/${condition}/${m}`));
      expect(coop && sides.every(Boolean), 'all four runs of this condition exist').toBe(true);
      const bound = {};
      for (const key of COUNTED) {
        bound[key] = Math.max(...sides.map((t) => t.counts[key]));
        expect(coop.counts[key], `${key}: cooperative <= max(one-sided)`).toBeLessThanOrEqual(bound[key]);
      }
      const rank = { success: 1, failed: 0 };
      const best = Math.max(...sides.map((t) => rank[t.outcome.iframe]));
      expect(rank[coop.outcome.iframe], 'embedded outcome not worse').toBeGreaterThanOrEqual(best);
      await saveTrace(testInfo, `modes-${condition}-bound`, { bound, cooperative: coop.counts });
    });
  }

  for (const mode of ['consumer', 'cooperative']) {
    test(`nostorage / ${mode}: the new-window option reaches the course top-level and is recorded as a fallback, not as embedded`, async ({ browser }, testInfo) => {
      const trace = await run(browser, mode, 'nostorage', { click: true });
      await saveTrace(testInfo, `modes-nostorage-${mode}-fallback`, trace);
      expect(trace.outcome.iframe).toBe('failed');
      expect(trace.outcome.toplevel).toBe('success');
      expect(trace.counts.windows, 'exactly one window, opened by the user').toBe(1);
      expect(trace.consumer.fallback).toBe('opened');
      // The failed launch in the frame (as without the plugin) plus exactly one fresh launch in the new window.
      const baseline = getResult(`${testInfo.project.name}/${testInfo.project.name === 'webkit' ? 'default' : 'nostorage'}/none`);
      expect(baseline, 'the baseline run of this condition exists').toBeTruthy();
      expect(trace.counts.oidcLogin).toBe(2 * baseline.counts.oidcLogin);
      expect(trace.counts.authRequest).toBe(baseline.counts.authRequest + 1);
      expect(trace.counts.toolLaunch).toBe(baseline.counts.toolLaunch + 1);
      // Diagnostics hand-over: the fallback and (cooperative) the detected problem are in A's log with the same id.
      const cid = trace.consumer.cid;
      expect(cid).toMatch(/^[a-f0-9]{32}$/);
      await expect.poll(() => Number(sql('a', `select count(*) from mdl_logstore_standard_log where component = 'local_ltixsembed'
        and eventname like '%fallback_launched' and other like '%${cid}%'`)), { timeout: 15000 }).toBe(1);
      if (mode === 'cooperative') {
        expect(Number(sql('a', `select count(*) from mdl_logstore_standard_log where component = 'local_ltixsembed'
          and eventname like '%embed_state_reported' and other like '%${cid}%'`))).toBe(1);
      }
    });
  }

  test('mobile viewport: core launches the tool in the same window (no iframe), the plugin adds nothing', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-safari', 'mobile project only');
    applyMode('cooperative', { a: { handshaketimeout: 4 }, b: { providerltipages: 1 } });
    const context = await browser.newContext({ ...require('@playwright/test').devices['iPhone 14'] });
    try {
      const page = await context.newPage();
      await login(page, seed.urls.a, learner(seed));
      await page.goto(seed.lti_activity.view_url);
      await page.waitForTimeout(8000);
      const result = {
        url: page.url().split('?')[0],
        frames: page.frames().length,
        consumerNotice: await page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]').count(),
      };
      await saveTrace(testInfo, 'modes-mobile-cooperative', { mobile: result });
      expect(result.frames, 'no iframe on a mobile device').toBe(1);
      expect(result.consumerNotice, 'no platform notice without an iframe').toBe(0);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('cooperative with peer bridge "required" and providerltipages: a silent tool is a blocked frame; "auto" is not', async ({ browser }, testInfo) => {
    // The tool's allow-list excludes A, so B stays silent towards A although it runs the plugin.
    const silent = await run(browser, 'cooperative', 'default', {
      extra: { a: { peerbridge: 'required' }, b: { allowedorigins: 'https://other.example' } },
    });
    await saveTrace(testInfo, 'modes-required-silent', silent);
    // The browser's own plugin-free outcome, measured here (not assumed): WebKit blocks third-party cookies by default.
    const base = getResult(`${testInfo.project.name}/default/none`)
      || { outcome: (await run(browser, 'none', 'default')).outcome };
    expect(silent.outcome.iframe).toBe(base.outcome.iframe);
    expect(silent.consumer.peer).toBe('silent');
    expect(silent.consumer.current).toBe('blocked_frame');
    expect(silent.counts.windows).toBe(0);
    const auto = await run(browser, 'cooperative', 'default', { extra: { b: { allowedorigins: 'https://other.example' } } });
    await saveTrace(testInfo, 'modes-auto-silent', auto);
    expect(auto.consumer.current).toBe('no_peer');
    expect(auto.consumer.noticeVisible).toBe(false);
  });

  test('automatic fallback mode opens at most one window per page load, only in cooperative mode with a reported problem', async ({ browser }, testInfo) => {
    for (const mode of ['consumer', 'cooperative']) {
      const trace = await run(browser, mode, 'nostorage', { extra: { a: { fallbackmode: 'auto' } } });
      await saveTrace(testInfo, `modes-auto-${mode}`, trace);
      expect(trace.counts.windows).toBe(mode === 'cooperative' ? 1 : 0);
      if (mode === 'cooperative') {
        expect(trace.consumer.fallback).toBe('auto_opened');
        expect(trace.outcome.iframe).toBe('failed');
      }
    }
  });
});
