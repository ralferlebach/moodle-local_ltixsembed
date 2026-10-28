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
 */

/*
 * Single-site suite: administration pages, the fallback endpoint's request checks and the notice on an
 * embedded activity. Needs one Moodle site with the plugin and an embedded LTI activity (seed.php),
 * or the two-site stack (site A is used then).
 */

const { test, expect } = require('@playwright/test');
const { login, singleSiteEnv, setConfig, loadSeed, sql, snapshotStackOnce, restoreStack } = require('./support');

const env = singleSiteEnv();

test.describe('local_ltixsembed single site', () => {
  let snap = null;
  test.skip(env === null, 'no site configured: run seed.php (LXS_*) or start the two-site stack');

  test.beforeAll(() => {
    // On the two-site stack the plugin is installed but switched off; the CI seed enables it itself.
    if (env && env.source.startsWith('two-site')) {
      setConfig('a', 'consumerenabled', 1);
      setConfig('a', 'allowedorigins', loadSeed().urls.b);
      // The notice only belongs on activities that open in an embedded frame (launchcontainer 3).
      const instance = loadSeed().lti_activity.instance;
      snap = snapshotStackOnce();
      sql('a', `update mdl_lti set launchcontainer=3 where id=${instance}`);
    }
  });

  test.afterAll(() => {
    if (snap !== null) {
      expect(restoreStack(snap), 'the stack configuration is restored').toEqual([]);
    }
  });

  test('the capability test page runs in the browser and reports a state', async ({ page }) => {
    await login(page, env.base, env.admin);
    await page.goto(`${env.base}/local/ltixsembed/test.php`);
    const state = page.locator('[data-region="state"][data-lxs-state-value]');
    await expect(state).toBeVisible({ timeout: 15000 });
    // Opened directly (not in a frame) the page is a top-level window with working storage.
    await expect(state).toHaveAttribute('data-lxs-state-value', 'top_level');
    await expect(page.locator('[data-feature="framed"]')).toHaveText(/no|nein/i);
    await expect(page.locator('[data-feature="cookie"]')).toHaveText(/yes|ja/i);
    await expect(page.locator('body')).not.toContainText('testing...');
  });

  test('the configuration audit lists findings and effective settings without changing anything', async ({ page }) => {
    await login(page, env.base, env.admin);
    await page.goto(`${env.base}/local/ltixsembed/audit.php`);
    await expect(page.locator('[data-region="findings"] li').first()).toBeVisible();
    await expect(page.locator('[data-region="local_ltixsembed-audit"]')).toContainText(/allowframembedding/);
    // A GET page: it must not offer any form that changes settings.
    await expect(page.locator('[data-region="local_ltixsembed-audit"] form')).toHaveCount(0);
  });

  test('learners cannot open the diagnostics', async ({ page }) => {
    await login(page, env.base, env.student);
    for (const path of ['test.php', 'audit.php']) {
      await page.goto(`${env.base}/local/ltixsembed/${path}`);
      await expect(page.locator('body')).toContainText(/Access denied|Zugriff verweigert|permission|Berechtigung/i);
      await expect(page.locator('[data-region="local_ltixsembed-audit"]')).toHaveCount(0);
    }
  });

  test('the settings page accepts only bare https origins', async ({ page }) => {
    await login(page, env.base, env.admin);
    await page.goto(`${env.base}/admin/settings.php?section=local_ltixsembed`);
    const field = page.locator('textarea[name="s_local_ltixsembed_allowedorigins"]');
    const original = await field.inputValue();
    for (const bad of ['https://*.evil.example.net', 'https://tool.example.org/some/path', 'javascript:alert(1)']) {
      await field.fill(bad);
      await page.locator('button:has-text("Save changes"), input[value="Save changes"]').first().click();
      await expect(page.locator('body')).toContainText(/Not a valid origin|Kein gültiger Origin/);
      await page.goto(`${env.base}/admin/settings.php?section=local_ltixsembed`);
    }
    const good = original || 'https://tool.example.org';
    await field.fill(good);
    await page.locator('button:has-text("Save changes"), input[value="Save changes"]').first().click();
    // The "Changes saved" toast fades, so check the stored value instead.
    await page.goto(`${env.base}/admin/settings.php?section=local_ltixsembed`);
    await expect(page.locator('textarea[name="s_local_ltixsembed_allowedorigins"]')).toHaveValue(good);
  });

  test('the embedded activity offers the new-window option with a POST form that carries no secret in its URL', async ({ page }) => {
    await login(page, env.base, env.student);
    await page.goto(`${env.base}/mod/lti/view.php?id=${env.cmid}`);
    const notice = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
    await expect(notice).toHaveCount(1);
    const form = notice.locator('form[data-region="fallback-form"]');
    await expect(form).toHaveAttribute('method', 'post');
    await expect(form).toHaveAttribute('target', '_blank');
    const action = await form.getAttribute('action');
    expect(action).toMatch(/\/local\/ltixsembed\/fallback\.php$/);
    expect(action).not.toMatch(/[?&](nonce|sesskey|token)=/);
    await expect(form.locator('input[name="nonce"]')).toHaveValue(/^v1\./);
  });

  test('the fallback endpoint rejects GET, a missing sesskey, a replayed token and caller-chosen URLs', async ({ page }) => {
    await login(page, env.base, env.student);
    await page.goto(`${env.base}/mod/lti/view.php?id=${env.cmid}`);
    const form = page.locator('form[data-region="fallback-form"]');
    const action = await form.getAttribute('action');
    const sesskey = await page.evaluate(() => window.M.cfg.sesskey);
    const nonce = await form.locator('input[name="nonce"]').inputValue();
    const endpoint = new URL(action, env.base).toString();

    const get = await page.request.get(`${endpoint}?cmid=${env.cmid}`, { maxRedirects: 0 });
    expect(await get.text()).toMatch(/must be sent as a form|als Formular/);

    const nosesskey = await page.request.post(endpoint, { form: { cmid: env.cmid, nonce, reason: 'manual' }, maxRedirects: 0 });
    expect([301, 302, 303, 307]).not.toContain(nosesskey.status());

    const evil = await page.request.post(endpoint, {
      form: { cmid: env.cmid, nonce, sesskey, reason: 'manual', url: 'https://evil.example/', returnurl: 'https://evil.example/', wwwroot: 'https://evil.example' },
      maxRedirects: 0,
    });
    expect([302, 303]).toContain(evil.status());
    const location = new URL(evil.headers().location, env.base);
    expect(location.origin).toBe(new URL(env.base).origin);
    expect(location.pathname).toMatch(/\/mod\/lti\/launch\.php$/);
    expect(location.search).toBe(`?id=${env.cmid}`);

    const replay = await page.request.post(endpoint, { form: { cmid: env.cmid, nonce, sesskey, reason: 'manual' }, maxRedirects: 0 });
    expect([301, 302, 303, 307]).not.toContain(replay.status());
  });

  test('an outsider cannot use the fallback endpoint for an activity of a course they are not in', async ({ page }) => {
    test.skip(!env.outsider || !env.outsider.username, 'the two-site stack has no user outside the course on site A');
    await login(page, env.base, env.outsider);
    const sesskey = await page.evaluate(() => window.M.cfg.sesskey);
    const response = await page.request.post(`${env.base}/local/ltixsembed/fallback.php`, {
      form: { cmid: env.cmid, nonce: `v1.${'A'.repeat(22)}.${'9'.repeat(10)}.${'A'.repeat(43)}`, sesskey, reason: 'manual' },
      maxRedirects: 0,
    });
    expect([301, 302, 303, 307]).not.toContain(response.status());
  });
});
