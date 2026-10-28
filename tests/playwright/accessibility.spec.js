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
 * FAM-04 / CE-04 accessibility checks: axe-core (WCAG 2.0/2.1/2.2 A and AA rules, including colour contrast) on every
 * region this plugin renders, plus structural and keyboard-only checks. Scope: axe is limited to the plugin's own
 * regions; violations elsewhere on the Moodle page belong to core/theme and are only recorded. No screen reader
 * (NVDA/JAWS/VoiceOver) ran: not verified.
 *
 * Former header - FAM-04 accessibility checks (automated, structural):
 * the notice is a live region, the fallback is reachable and operable with the keyboard alone, every
 * control has an accessible name, and the storage access button in the tool frame is focusable and named.
 * These checks do not replace a manual screen-reader pass (not done).
 */

const { test, expect } = require('@playwright/test');
const { stackAvailable, loadSeed, getUser, login, sql, setConfig, blockFrameStorage, snapshotStackOnce, restoreStack,
} = require('./support');

test.describe.configure({ mode: 'serial', retries: 1 });

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/** axe-core source: from the package (devDependency) or LXS_AXE_PATH. */
function axeSource() {
  const fs = require('fs');
  const file = process.env.LXS_AXE_PATH || require.resolve('axe-core/axe.min.js');
  return fs.readFileSync(file, 'utf8');
}

/**
 * Run axe in a frame, limited to a selector; returns {violations (scoped), outside (rule ids elsewhere)}.
 *
 * @param {import('@playwright/test').Frame} frame The frame.
 * @param {string} selector The plugin region.
 */
async function axe(frame, selector) {
  await frame.evaluate(axeSource());
  return frame.evaluate(async ({ sel, tags }) => {
    const scoped = await window.axe.run({ include: [sel] }, { runOnly: { type: 'tag', values: tags } });
    const page = await window.axe.run(document, { runOnly: { type: 'tag', values: tags } });
    const brief = (v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, targets: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) });
    return {
      violations: scoped.violations.map(brief),
      passes: scoped.passes.length,
      outside: page.violations.filter((v) => !scoped.violations.some((x) => x.id === v.id)).map(brief),
    };
  }, { sel: selector, tags: WCAG_TAGS });
}

test.describe('local_ltixsembed accessibility (structural)', () => {
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
    setConfig('a', 'fallbackmode', 'suggest');
    setConfig('b', 'allowedorigins', seed.urls.a);
    setConfig('b', 'providerenabled', 1);
  });

  test.afterAll(() => {
    if (snap) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  test('the notice is a live region and the new-window fallback works with the keyboard alone', async ({ browser }) => {
    const context = await browser.newContext();
    try {
      await blockFrameStorage(context, seed.urls.b);
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
      await expect(notice).toHaveAttribute('data-lxs-current', /^(storage_unavailable|session_lost|blocked_frame|needs_user_intervention)$/, { timeout: 60000 });

      const live = notice.locator('[data-region="state-message"]');
      await expect(live).toHaveAttribute('role', 'status');
      await expect(live).toHaveAttribute('aria-live', 'polite');
      await expect(live).toBeVisible();

      const summary = notice.locator('summary');
      await expect(summary).toHaveText(/\S/);
      // The disclosure widget is keyboard operable: focus + Enter/Space toggles it. Opening is also done by the script on a problem state.
      await summary.focus();
      await expect(summary).toBeFocused();
      const button = notice.getByRole('button', { name: /new window|neuem Fenster/i });
      await expect(button).toBeAttached();
      // Every control has an accessible name.
      const unnamed = await notice.evaluate((root) => Array.from(root.querySelectorAll('button, a, input:not([type="hidden"]), summary'))
        .filter((el) => !(el.getAttribute('aria-label') || el.textContent.trim())).length);
      expect(unnamed).toBe(0);

      // Keyboard only: focus the button and press Enter; a new window opens on B.
      await button.focus();
      const [popup] = await Promise.all([context.waitForEvent('page'), page.keyboard.press('Enter')]);
      await popup.waitForURL(/b\.tool-b\.test\/course\/view\.php/, { timeout: 90000 });
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('axe-core: the platform notice in a problem state and the tool notice in the frame have no WCAG A/AA violation', async ({ browser }, testInfo) => {
    const context = await browser.newContext();
    try {
      await blockFrameStorage(context, seed.urls.b);
      await context.addInitScript(({ origin }) => {
        if (window.top !== window && window.location.origin === origin) {
          Document.prototype.hasStorageAccess = () => Promise.resolve(false);
          Document.prototype.requestStorageAccess = () => Promise.reject(new DOMException('denied', 'NotAllowedError'));
        }
      }, { origin: seed.urls.b });
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
      await expect(notice).toHaveAttribute('data-lxs-current', 'needs_user_intervention', { timeout: 60000 });
      await expect(notice.locator('[data-region="state-message"]')).toBeVisible();
      const platform = await axe(page.mainFrame(), '[data-region="local_ltixsembed-notice"]');
      const frame = page.frames().find((f) => f.parentFrame() === page.mainFrame() && f.url().startsWith(seed.urls.b));
      await expect(frame.locator('[data-region="local_ltixsembed-notice"] [role="alert"]')).toBeVisible();
      const tool = await axe(frame, '[data-region="local_ltixsembed-notice"]');
      await testInfo.attach('axe', { body: JSON.stringify({ platform, tool }, null, 2), contentType: 'application/json' });
      expect(platform.violations).toEqual([]);
      expect(tool.violations).toEqual([]);
      expect(platform.passes).toBeGreaterThan(0);
      expect(tool.passes).toBeGreaterThan(0);
    } finally {
      await context.close().catch(() => {});
    }
  });

  test('axe-core: the capability test page and the audit page have no WCAG A/AA violation in the plugin regions', async ({ page }, testInfo) => {
    await login(page, seed.urls.a, seed.admins.a);
    await page.goto(`${seed.urls.a}/local/ltixsembed/test.php`);
    await expect(page.locator('[data-region="local_ltixsembed-diagnostics"] [data-region="state"]')).not.toHaveText(/testing/i, { timeout: 30000 });
    const diag = await axe(page.mainFrame(), '[data-region="local_ltixsembed-diagnostics"]');
    await page.goto(`${seed.urls.a}/local/ltixsembed/audit.php`);
    const audit = await axe(page.mainFrame(), '[data-region="local_ltixsembed-audit"]');
    await testInfo.attach('axe-admin', { body: JSON.stringify({ diag, audit }, null, 2), contentType: 'application/json' });
    expect(diag.violations).toEqual([]);
    expect(audit.violations).toEqual([]);
  });

  test('the tool-side notice is an alert with named controls', async ({ browser }) => {
    const context = await browser.newContext();
    try {
      await blockFrameStorage(context, seed.urls.b);
      await context.addInitScript(({ origin }) => {
        if (window.top !== window && window.location.origin === origin) {
          Document.prototype.hasStorageAccess = () => Promise.resolve(false);
          Document.prototype.requestStorageAccess = () => Promise.reject(new DOMException('denied', 'NotAllowedError'));
        }
      }, { origin: seed.urls.b });
      const page = await context.newPage();
      await login(page, seed.urls.a, getUser(seed, 'studentA2'));
      await page.goto(activity.view_url);
      await expect(page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]'))
        .toHaveAttribute('data-lxs-current', 'needs_user_intervention', { timeout: 60000 });
      const frame = page.frames().find((f) => f.url().startsWith(seed.urls.b));
      const alert = frame.locator('[data-region="local_ltixsembed-notice"] [role="alert"]');
      await expect(alert).toBeVisible();
      const button = frame.getByRole('button', { name: /keep your session|Sitzung halten/i });
      await expect(button).toBeVisible();
      await button.focus();
      await expect(button).toBeFocused();
      await page.keyboard.press('Enter');
      // The outcome is announced in a status region.
      await expect(frame.locator('[data-region="storage-access-outcome"][role="status"]')).toBeVisible();
    } finally {
      await context.close().catch(() => {});
    }
  });
});
