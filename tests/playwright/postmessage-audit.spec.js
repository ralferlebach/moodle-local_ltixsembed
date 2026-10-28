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
 * CE-04: origin, sender and replay validation in a real browser, and no unauthorised postMessage data flow.
 *
 * Every message DELIVERED to any window of the context is recorded (receiver origin, sender origin, kind, key names;
 * support.recordLaunch). Asserted for cooperative operation in Chromium:
 *  - the tool hears only "welcome" from the platform, the platform only "hello"/"state" (plus the platform-storage
 *    capability probe) from the tool; payloads carry only the fixed state/facts keys;
 *  - nothing is delivered to a third origin, even when a third origin embeds the tool;
 *  - a captured genuine message replayed from the genuine frame, a tampered nonce, an expired message, a wrong
 *    context and a genuine-looking message from a second window of the tool origin are all dropped (counter
 *    data-lxs-rejected), and the detected state does not change.
 */

const { test, expect } = require('@playwright/test');
const {
  stackAvailable, loadSeed, getUser, login, sql, setConfig, blockFrameStorage, snapshotStackOnce, restoreStack,
  applyMode, recordLaunch, saveTrace, learner,
} = require('./support');

const NOTICE = '[data-region="local_ltixsembed-notice"][data-role="consumer"]';
const EVIL = 'https://evil.lxs-attacker.test';

test.describe('local_ltixsembed CE-04 postMessage audit', () => {
  test.skip(!stackAvailable(), 'the two-site stack is not available (LTI_SEED)');

  let seed;
  let snap;

  test.beforeAll(() => {
    seed = loadSeed();
    snap = snapshotStackOnce();
    sql('a', `update mdl_lti set launchcontainer=3 where id=${seed.lti_activity.instance}`);
    applyMode('cooperative', {
      a: { handshaketimeout: 4, fallbackmode: 'suggest', peerbridge: 'auto' },
      b: { providerltipages: 1, storageaccess: 1 },
    });
  });

  test.afterAll(() => {
    if (snap) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  /** Open the activity in a context with the tool's frame storage cut, so the bridge reports a problem state. */
  async function openWithProblem(browser) {
    const name = browser.browserType().name();
    const context = await browser.newContext(name === 'firefox' ? { ignoreHTTPSErrors: true } : {});
    if (name !== 'webkit') {
      // WebKit blocks third-party cookies natively; elsewhere the frame storage is cut by the emulation.
      await blockFrameStorage(context, seed.urls.b);
    }
    // Keep the raw bridge messages the platform receives, to replay them later.
    await context.addInitScript(() => {
      if (window.top === window) {
        window.__lxsCaptured = [];
        window.addEventListener('message', (e) => {
          if (e.data && typeof e.data === 'object' && 'lxs' in e.data) {
            window.__lxsCaptured.push(JSON.parse(JSON.stringify(e.data)));
          }
        }, true);
      }
    });
    const page = await context.newPage();
    await login(page, seed.urls.a, learner(seed));
    const rec = await recordLaunch(context, seed);
    await page.goto(seed.lti_activity.view_url);
    const notice = page.locator(NOTICE);
    await expect(notice).toHaveAttribute('data-lxs-current', /^(storage_unavailable|session_lost|needs_user_intervention)$/,
      { timeout: 60000 });
    return { context, page, notice, rec };
  }

  test('only the agreed messages flow, only between A and B, with only the fixed keys', async ({ browser }, testInfo) => {
    const { context, page, rec } = await openWithProblem(browser);
    try {
      await page.waitForTimeout(3000);
      const trace = await rec.finish(page);
      await saveTrace(testInfo, 'pm-flow', { counts: trace.counts, messages: trace.messages, consumer: trace.consumer });
      const bridge = trace.messages.filter((m) => m.kind.startsWith('lxs:'));
      expect(bridge.length).toBeGreaterThan(0);
      for (const m of trace.messages) {
        expect([seed.urls.a, seed.urls.b]).toContain(m.receiver);
        expect([seed.urls.a, seed.urls.b]).toContain(m.sender);
      }
      for (const m of bridge) {
        if (m.receiver === seed.urls.b) {
          expect(m.sender).toBe(seed.urls.a);
          expect(m.kind).toBe('lxs:welcome');
          expect(m.dataKeys).toEqual([]);
        } else {
          expect(m.sender).toBe(seed.urls.b);
          expect(['lxs:hello', 'lxs:state']).toContain(m.kind);
          expect(m.dataKeys.every((k) => ['facts', 'state'].includes(k))).toBe(true);
        }
        expect(m.keys.every((k) => ['lxs', 'type', 'mid', 'nonce', 'ctx', 'iat', 'exp', 'data'].includes(k))).toBe(true);
      }
      const others = trace.messages.filter((m) => !m.kind.startsWith('lxs:'));
      // The only other message is the tool's LTI platform-storage capability probe to the allow-listed platform.
      expect(others.every((m) => m.kind === 'subject:lti.capabilities' && m.receiver === seed.urls.a)).toBe(true);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('replayed, tampered, expired, wrong-context and wrong-sender messages are dropped in the browser', async ({ browser }, testInfo) => {
    const { context, page, notice } = await openWithProblem(browser);
    try {
      const genuineState = await notice.getAttribute('data-lxs-current');
      const captured = await page.evaluate(() => window.__lxsCaptured);
      const state = captured.find((m) => m.type === 'state');
      expect(state, 'a genuine state message was received').toBeTruthy();
      const before = Number(await notice.getAttribute('data-lxs-rejected'));
      const frame = page.frames().find((f) => f.parentFrame() === page.mainFrame() && f.url().startsWith(seed.urls.b));
      const fresh = (over) => ({ ...state, mid: [...Array(32)].map(() => '0123456789abcdef'[Math.floor(Math.random() * 16)]).join(''),
        iat: Date.now(), exp: Date.now() + 20000, ...over, data: { state: 'embedded' } });
      const cases = [
        ['replay', { ...state }, 'replay'], // Identical message id, sent while it is still valid.
        ['tamperedNonce', fresh({ nonce: 'f'.repeat(state.nonce.length) }), 'nonce'],
        ['expired', fresh({ iat: Date.now() - 120000, exp: Date.now() - 60000 }), 'expired'],
        ['wrongContext', fresh({ ctx: 'cm:999999' }), 'context'],
        ['overlongLifetime', fresh({ exp: Date.now() + 3600000 }), 'lifetime'],
      ];
      const observed = {};
      let count = before;
      const expectDropped = async (name, reason) => {
        count++;
        await expect.poll(async () => Number(await notice.getAttribute('data-lxs-rejected')), { timeout: 10000 }).toBe(count);
        observed[name] = await notice.getAttribute('data-lxs-lastreject');
        expect(observed[name], name).toBe(reason);
      };
      for (const [name, msg, reason] of cases) {
        await frame.evaluate(({ m, target }) => window.parent.postMessage(m, target), { m: msg, target: seed.urls.a });
        await expectDropped(name, reason);
      }
      // Wrong sender: a second window of the tool origin (same origin, different source) with a fresh, otherwise valid message.
      await page.evaluate((src) => {
        const extra = document.createElement('iframe');
        extra.src = src;
        document.body.appendChild(extra);
      }, `${seed.urls.b}/login/index.php`);
      await expect.poll(() => page.frames().filter((f) => f.url().startsWith(`${seed.urls.b}/login/`)).length, { timeout: 30000 })
        .toBeGreaterThan(0);
      const second = page.frames().find((f) => f.url().startsWith(`${seed.urls.b}/login/`));
      await second.waitForLoadState('load');
      await second.evaluate(({ m, target }) => window.parent.postMessage(m, target), { m: fresh({}), target: seed.urls.a });
      await expectDropped('secondToolWindow', 'source');
      // The platform's own window (origin not on the allow-list).
      await page.evaluate((m) => window.postMessage(m, '*'), fresh({}));
      await expectDropped('platformWindow', 'origin');

      await expect(notice).toHaveAttribute('data-lxs-current', genuineState);
      const expected = count;
      await saveTrace(testInfo, 'pm-forged', { genuineState, rejectedBefore: before, rejectedAfter: expected, observed });
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('a third origin that embeds the tool receives nothing and cannot steer it', async ({ browser }, testInfo) => {
    const context = await browser.newContext();
    try {
      const rec = await recordLaunch(context, seed);
      await context.route(`${EVIL}/**`, (route) => route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!doctype html><title>third origin</title><iframe id="t" src="${seed.urls.b}/login/index.php" width="600" height="400"></iframe>`,
      }));
      // Chromium's Local Network Access check would already stop a public origin from framing the (127.0.0.1) stack.
      // That is a browser protection, not the plugin's: grant it so the plugin's own guarantee is what is tested.
      if (browser.browserType().name() === 'chromium') {
        await context.grantPermissions(['local-network-access'], { origin: EVIL });
      }
      const page = await context.newPage();
      await page.goto(`${EVIL}/`);
      await expect.poll(() => page.frames().filter((f) => f.url().startsWith(seed.urls.b)).length, { timeout: 30000 }).toBeGreaterThan(0);
      const tool = page.frames().find((f) => f.url().startsWith(seed.urls.b));
      await tool.waitForLoadState('load');
      // The tool runs its bridge here (the launch page carries it) ...
      await expect(tool.locator('[data-region="local_ltixsembed-notice"][data-role="provider"]')).toBeAttached({ timeout: 20000 });
      // ... the third origin sends a forged welcome ...
      await page.evaluate((target) => document.getElementById('t').contentWindow.postMessage({
        lxs: 1, type: 'welcome', mid: 'a'.repeat(32), nonce: 'b'.repeat(32), ctx: 'cm:1', iat: Date.now(), exp: Date.now() + 30000, data: {},
      }, target), seed.urls.b);
      await page.waitForTimeout(5000);
      const delivered = rec.messages.filter((m) => m.receiver === EVIL);
      await saveTrace(testInfo, 'pm-third-origin', { delivered, all: rec.messages });
      expect(delivered, 'nothing from the tool reaches the third origin').toEqual([]);
      await expect(tool.locator('[data-region="local_ltixsembed-notice"][data-role="provider"]')).toHaveAttribute('data-lxs-channel', 'none');
    } finally {
      await context.close().catch(() => {});
    }
  });
});
