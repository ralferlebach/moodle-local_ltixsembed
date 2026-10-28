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
 * Support code for the LTI Cross-Site Embed browser suites.
 *
 * Two kinds of suite exist:
 *  - single-site specs (single-site.spec.js) need one Moodle site with the plugin; the CI workflow
 *    runs them against a throw-away site seeded by seed.php (LXS_* variables);
 *  - two-site specs (two-site-*.spec.js) need the LTI test stack: Moodle A (platform) embedding
 *    Moodle B (tool). They read the stack's seed file (LTI_SEED, default
 *    /home/claude/e2e/state/seed.json) and drive both sites' configuration through Moodle CLI.
 *
 * The suites never relax a browser protection. Where third-party storage has to be unavailable in
 * the frame, it is EMULATED (see blockFrameStorage) and every spec says so.
 */

const fs = require('fs');
const { execFileSync } = require('child_process');
const { request: playwrightRequest } = require('@playwright/test');

const SEED_PATH = process.env.LTI_SEED || '/home/claude/e2e/state/seed.json';

/** Whether the two-site stack's seed file exists. */
function stackAvailable() {
  return fs.existsSync(SEED_PATH);
}

/** Load the two-site seed. Expected shape: urls.{a,b}, admins.{a,b}, users[{key,username,password}], lti_activity.{cmid,instance,view_url}. */
function loadSeed() {
  return JSON.parse(fs.readFileSync(SEED_PATH, 'utf8'));
}

/** A seeded user by key (for example "studentA2") or username. */
function getUser(seed, key) {
  const user = seed.users.find((u) => u.key === key || u.username === key);
  if (!user) {
    throw new Error(`user ${key} is not in the seed`);
  }
  return user;
}

/**
 * Log in through the Moodle login form.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @param {string} base Site base URL.
 * @param {{username: string, password: string}} credentials The credentials.
 */
async function login(page, base, credentials) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(`${base}/login/index.php`);
    // Filling before the page's late scripts ran produced sporadic "Invalid login" on the loaded stack.
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.fill('#username', credentials.username);
    await page.fill('#password', credentials.password);
    await page.click('#loginbtn');
    const left = await page.waitForURL((u) => !/\/login\/index\.php$/.test(u.pathname), { timeout: 20000 })
      .then(() => true, () => false);
    if (left) {
      await page.waitForLoadState('load');
      return;
    }
  }
  throw new Error(`login failed for ${credentials.username}`);
}

/** Physical stack sites: Moodle tree, database, port and data root (A2/B2 share the trees of A/B with their own DB). */
const SITES = {
  a: { root: process.env.LTI_A_ROOT || '/home/claude/moodle', port: '5432', db: 'moodle', dbname: '', base: '' },
  b: { root: process.env.LTI_B_ROOT || '/home/claude/moodle53', port: '5433', db: 'moodle53', dbname: '', base: '' },
  a2: { root: process.env.LTI_A_ROOT || '/home/claude/moodle', port: '5432', db: 'moodle_e2e_a2', dbname: 'moodle_e2e_a2',
    base: '/home/claude/data/e2e_a2' },
  b2: { root: process.env.LTI_B_ROOT || '/home/claude/moodle53', port: '5433', db: 'moodle53_e2e_b2',
    dbname: 'moodle53_e2e_b2', base: '/home/claude/data/e2e_b2' },
};

/**
 * The physical site behind a role of the current seed: 'a' = platform, 'b' = tool. The default seed uses sites a and b;
 * a pair seed (state/seed-pair-45.json: A -> A2, seed-pair-53.json: B -> B2) names them in "sites".
 *
 * @param {'a'|'b'} role The role.
 * @returns {Object} root, port, db, dbname, base, id, url
 */
function siteOf(role) {
  const seed = loadSeed();
  const id = seed.sites && seed.sites[role] ? seed.sites[role].id : role;
  return { ...SITES[id], id, url: seed.urls[role] };
}

/** Environment for Moodle CLI on a site (A2/B2 need their own DB and data root). */
function cliEnv(role) {
  const site = siteOf(role);
  const env = { ...process.env, LTI_WWWROOT: site.url };
  if (site.dbname) {
    env.LTI_DBNAME = site.dbname;
    env.LTI_BASE = site.base;
  }
  return env;
}

/** Run a psql statement against the database of a role's site. */
function sql(role, statement) {
  const site = siteOf(role);
  return execFileSync('psql', ['-h', '127.0.0.1', '-p', site.port, '-U', 'moodle', site.db, '-At', '-c', statement],
    { env: { ...process.env, PGPASSWORD: 'moodle' } }).toString().trim();
}

/**
 * Set a plugin setting of one stack site through Moodle's own CLI (so the config cache is invalidated).
 *
 * @param {'a'|'b'} site The role of the site in the current seed.
 * @param {string} name The setting name of local_ltixsembed.
 * @param {string|number} value The value.
 * @param {string} [component] Another component, for example "core".
 */
function setConfig(site, name, value, component = 'local_ltixsembed') {
  const args = ['admin/cli/cfg.php', `--name=${name}`, `--set=${value}`];
  if (component !== 'core') {
    args.splice(1, 0, `--component=${component}`);
  }
  execFileSync('php', args, { cwd: siteOf(site).root, env: cliEnv(site), stdio: 'pipe' });
}

/**
 * EMULATE a browser that gives a third-party frame no cookie storage at all.
 *
 * What it does, in every page of the context:
 *  - for documents of the given origin that are shown inside a frame, document.cookie neither stores
 *    nor returns anything (JavaScript side), and
 *  - requests of such frames to that origin are sent by a separate, empty, throw-away HTTP client: no
 *    Cookie header goes out and no Set-Cookie comes back into the browser (HTTP side).
 * Top-level navigations to the origin - for example the new window of the fallback - are untouched.
 *
 * This is NOT the real protection of any browser: it reproduces the effect a fully blocking browser
 * (for instance Safari or Firefox "block all cross-site cookies") has on a Moodle tool. The
 * Playwright Chromium of the test machine does not offer a switch for real third-party cookie
 * blocking, and the stack's two sites share the registrable domain lti.test and are therefore
 * same-site anyway (older stack; the current one uses cross-site domains and newRealBlockingContext).
 *
 * @param {import('@playwright/test').BrowserContext} context The context.
 * @param {string} origin The origin whose frames lose storage, for example "https://b.lti.test".
 */
async function blockFrameStorage(context, origin) {
  await context.addInitScript((blocked) => {
    if (window.top !== window && window.location.origin === blocked) {
      Object.defineProperty(Document.prototype, 'cookie', {
        configurable: true,
        get() {
          return '';
        },
        set() {},
      });
    }
  }, origin);
  await context.route(`${origin}/**`, async (route) => {
    const request = route.request();
    const frame = request.frame();
    const inFrame = frame !== null && frame.parentFrame() !== null;
    if (!inFrame) {
      await route.continue().catch(() => {});
      return;
    }
    // A fresh API context per request: its cookie jar is empty and is thrown away afterwards. (route.fetch() would use
    // the BROWSER CONTEXT's cookie jar, add the stored cookies again and store the response's Set-Cookie there - the
    // 0.2.0 emulation leaked HTTP cookies that way; fixed in 0.3.0.)
    const api = await playwrightRequest.newContext();
    try {
      const headers = { ...(await request.allHeaders()) };
      delete headers.cookie;
      delete headers['content-length'];
      const body = request.postDataBuffer();
      const response = await api.fetch(request.url(), {
        method: request.method(), headers, data: body === null ? undefined : body, maxRedirects: 0, failOnStatusCode: false,
      });
      const merged = {};
      response.headersArray().filter((h) => h.name.toLowerCase() !== 'set-cookie').forEach((h) => {
        merged[h.name] = merged[h.name] ? `${merged[h.name]}, ${h.value}` : h.value;
      });
      await route.fulfill({ status: response.status(), headers: merged, body: await response.body() });
    } catch (e) {
      // The page or context went away while the request was in flight (test teardown): nothing to answer.
      await route.abort().catch(() => {});
    } finally {
      await api.dispose().catch(() => {});
    }
  });
}

/**
 * A Chromium context with REAL third-party cookie blocking (the browser's own "Block third-party
 * cookies" setting), provided by the two-site stack's helper. Resolves to null when the helper is
 * not installed.
 *
 * @param {Object} chromium Playwright's chromium launcher.
 * @returns {Promise<import('@playwright/test').BrowserContext|null>} The context, or null.
 */
async function newRealBlockingContext(chromium) {
  const helper = process.env.LTI_E2E_LIB || '/home/claude/e2e/lib/e2e.js';
  if (!fs.existsSync(helper)) {
    return null;
  }
  return require(helper).newBlockingContext(chromium);
}

/** Direct children of the page that are frames of the given origin. */
function framesOf(page, origin) {
  return page.frames().filter((f) => f.url().startsWith(origin));
}

/**
 * Connection data for the single-site specs.
 *
 * Taken from the LXS_* variables printed by seed.php (CI), or - when they are absent and the two-site
 * stack exists - from its seed file, using site A.
 *
 * @returns {{base: string, admin: Object, student: Object, cmid: string, source: string}|null}
 */
function singleSiteEnv() {
  if (process.env.LXS_BASE_URL) {
    return {
      base: process.env.LXS_BASE_URL,
      admin: { username: process.env.LXS_ADMIN_USER, password: process.env.LXS_ADMIN_PASS },
      student: { username: process.env.LXS_STUDENT_USER, password: process.env.LXS_STUDENT_PASS },
      outsider: { username: process.env.LXS_OUTSIDER_USER, password: process.env.LXS_OUTSIDER_PASS },
      cmid: process.env.LXS_CMID,
      source: 'LXS_*',
    };
  }
  if (stackAvailable()) {
    const seed = loadSeed();
    return {
      base: seed.urls.a,
      admin: seed.admins.a,
      student: getUser(seed, 'studentA2'),
      outsider: null,
      cmid: String(seed.lti_activity.cmid),
      source: 'two-site stack, site A',
    };
  }
  return null;
}

/**
 * The learner the mode specs launch as: studentA3 on the default seed, the last platform student of a pair seed, or
 * LXS_LEARNER.
 *
 * @param {Object} seed The seed.
 * @returns {Object} The seed user.
 */
function learner(seed) {
  const key = process.env.LXS_LEARNER
    || (seed.users.find((u) => u.key === 'studentA3') || seed.users.filter((u) => /^student/i.test(u.key)).pop()).key;
  return getUser(seed, key);
}

/** Settings of this plugin that the specs may change; all of them are snapshotted and restored. */
const PLUGIN_SETTINGS = ['consumerenabled', 'providerenabled', 'providerltipages', 'allowedorigins', 'noncelifetime',
  'handshaketimeout', 'fallbackmode', 'storageaccess', 'peerbridge'];

/** Remove a plugin setting of a stack site through Moodle's CLI. */
function unsetConfig(site, name, component = 'local_ltixsembed') {
  const args = ['admin/cli/cfg.php', `--name=${name}`, '--unset'];
  if (component !== 'core') {
    args.splice(1, 0, `--component=${component}`);
  }
  execFileSync('php', args, { cwd: siteOf(site).root, env: cliEnv(site), stdio: 'pipe' });
}

/**
 * Snapshot of everything the specs change on the shared stack: this plugin's settings on both sites, core
 * "allowframembedding" on both sites and the launch container of the seeded activity on A.
 *
 * @returns {Object} Opaque snapshot for restoreStack().
 */
function snapshotStack() {
  const seed = loadSeed();
  const plugin = {};
  for (const site of ['a', 'b']) {
    plugin[site] = {};
    const rows = sql(site, "select name || '=' || coalesce(value, '') from mdl_config_plugins where plugin = 'local_ltixsembed'");
    rows.split('\n').filter(Boolean).forEach((line) => {
      const i = line.indexOf('=');
      plugin[site][line.slice(0, i)] = line.slice(i + 1);
    });
  }
  return {
    plugin,
    frame: {
      a: sql('a', "select value from mdl_config where name = 'allowframembedding'"),
      b: sql('b', "select value from mdl_config where name = 'allowframembedding'"),
    },
    container: sql('a', `select launchcontainer from mdl_lti where id=${seed.lti_activity.instance}`),
  };
}

/**
 * Restore a snapshot taken by snapshotStack(). Returns a list of differences that remain (empty when fully restored).
 *
 * @param {Object} snap The snapshot.
 * @returns {string[]}
 */
function restoreStack(snap) {
  const seed = loadSeed();
  for (const site of ['a', 'b']) {
    for (const name of PLUGIN_SETTINGS) {
      const before = snap.plugin[site][name];
      if (before === undefined) {
        unsetConfig(site, name);
      } else {
        setConfig(site, name, before);
      }
    }
    if (snap.frame[site] !== '') {
      setConfig(site, 'allowframembedding', snap.frame[site], 'core');
    }
  }
  sql('a', `update mdl_lti set launchcontainer=${Number(snap.container)} where id=${seed.lti_activity.instance}`);
  const now = snapshotStack();
  const diff = [];
  for (const site of ['a', 'b']) {
    for (const name of PLUGIN_SETTINGS) {
      if (snap.plugin[site][name] !== now.plugin[site][name]) {
        diff.push(`${site}:${name} ${snap.plugin[site][name]} -> ${now.plugin[site][name]}`);
      }
    }
    if (snap.frame[site] !== now.frame[site]) {
      diff.push(`${site}:allowframembedding`);
    }
  }
  if (snap.container !== now.container) {
    diff.push('a:launchcontainer');
  }
  return diff;
}

/** Directory for run state and evidence (shared by all workers of one Playwright run). */
function runDir() {
  const path = require('path');
  const dir = path.join(process.env.LXS_EVIDENCE_DIR || path.join(require('os').tmpdir(), 'ltixsembed-evidence'),
    `run-${process.env.LXS_RUN_ID || 'adhoc'}`);
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

/**
 * Snapshot of the stack taken once per Playwright run: a worker that restarts after a failed test must not take a new
 * snapshot of the already changed configuration.
 *
 * @returns {Object} The snapshot.
 */
function snapshotStackOnce() {
  const seed = loadSeed();
  const pair = seed.sites ? `-${seed.sites.a.id}-${seed.sites.b.id}` : '';
  const file = require('path').join(runDir(), `stack-snapshot${pair}.json`);
  if (fs.existsSync(file)) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  }
  const snap = snapshotStack();
  fs.writeFileSync(file, JSON.stringify(snap));
  return snap;
}

/** Store one result of this run (survives worker restarts). */
function putResult(key, value) {
  const file = require('path').join(runDir(), 'results.json');
  const all = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
  all[key] = value;
  fs.writeFileSync(file, JSON.stringify(all));
}

/** Read one result of this run. */
function getResult(key) {
  const file = require('path').join(runDir(), 'results.json');
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8'))[key] : undefined;
}

/** The operating modes of CE-02 and what each means for the two sites' role switches. */
const MODES = {
  none: { a: 0, b: 0 },
  consumer: { a: 1, b: 0 },
  provider: { a: 0, b: 1 },
  cooperative: { a: 1, b: 1 },
};

/**
 * Put the stack into one operating mode through the plugin's own switches (never by uninstalling): a side that is
 * "inactive" has both role switches off, an active side has both on.
 *
 * @param {string} mode One of the keys of MODES.
 * @param {Object} [extra] Extra settings: {a: {name: value}, b: {...}}.
 */
function applyMode(mode, extra = {}) {
  const seed = loadSeed();
  const m = MODES[mode];
  setConfig('a', 'consumerenabled', m.a);
  setConfig('a', 'providerenabled', m.a);
  setConfig('b', 'consumerenabled', m.b);
  setConfig('b', 'providerenabled', m.b);
  setConfig('a', 'allowedorigins', seed.urls.b);
  setConfig('b', 'allowedorigins', seed.urls.a);
  for (const site of ['a', 'b']) {
    for (const [name, value] of Object.entries(extra[site] || {})) {
      setConfig(site, name, value);
    }
  }
}

/**
 * Record what one embedded launch does, for the CE-02/CE-03 traces.
 *
 * Network side (context listeners): OIDC login initiations on the tool, authentication requests on the platform,
 * tool launch POSTs, platform launch requests, redirects of frame navigations, OIDC state cookies set by the tool
 * (Set-Cookie lti1p3_*), session cookies set by the tool, new windows (pages). Message side (init script in every
 * frame): every postMessage DELIVERED to any window, with receiver origin, sender origin, kind and key names only
 * (never values except the bridge type and state words).
 *
 * @param {import('@playwright/test').BrowserContext} context The context.
 * @param {Object} seed The stack seed.
 * @returns {Promise<Object>} The recorder with counts, messages and finish(page) -> trace.
 */
async function recordLaunch(context, seed) {
  const a = seed.urls.a;
  const b = seed.urls.b;
  const rec = {
    counts: { oidcLogin: 0, authRequest: 0, toolLaunch: 0, platformLaunch: 0, frameRedirects: 0, stateCookies: 0,
      toolSessionCookies: 0, windows: 0 },
    messages: [],
    started: Date.now(),
  };
  const pagesAtStart = context.pages().length;
  context.on('page', () => {
    rec.counts.windows++;
  });
  context.on('request', (request) => {
    const url = request.url();
    if (url.startsWith(`${b}/enrol/lti/login.php`)) {
      rec.counts.oidcLogin++;
    } else if (url.startsWith(`${a}/mod/lti/auth.php`)) {
      rec.counts.authRequest++;
    } else if (url.startsWith(`${b}/enrol/lti/launch.php`) && request.method() === 'POST') {
      rec.counts.toolLaunch++;
    } else if (url.startsWith(`${a}/mod/lti/launch.php`)) {
      rec.counts.platformLaunch++;
    }
  });
  context.on('response', async (response) => {
    try {
      const request = response.request();
      const frame = request.frame();
      if (request.isNavigationRequest() && frame && frame.parentFrame() !== null && response.status() >= 300 && response.status() < 400) {
        rec.counts.frameRedirects++;
      }
      if (response.url().startsWith(b)) {
        for (const h of await response.headersArray()) {
          if (h.name.toLowerCase() === 'set-cookie') {
            if (/^lti1p3_/.test(h.value)) {
              rec.counts.stateCookies++;
            }
            if (/^MoodleSession/.test(h.value) && !/MoodleSession[^=]*=deleted/.test(h.value)) {
              rec.counts.toolSessionCookies++;
            }
          }
        }
      }
    } catch (e) {
      // Response of a closed page.
    }
  });
  await context.exposeBinding('__lxsRecordMessage', ({ frame }, entry) => {
    rec.messages.push({ ...entry, frameUrl: frame ? frame.url().split('?')[0] : '' });
  });
  await context.addInitScript(() => {
    window.addEventListener('message', (event) => {
      const d = event.data;
      let kind = typeof d;
      let keys = [];
      let dataKeys = [];
      let state = null;
      if (d && typeof d === 'object') {
        keys = Object.keys(d).sort();
        if ('lxs' in d) {
          kind = `lxs:${d.type}`;
          dataKeys = d.data && typeof d.data === 'object' ? Object.keys(d.data).sort() : [];
          state = d.data && typeof d.data.state === 'string' ? d.data.state : null;
        } else if (typeof d.subject === 'string') {
          kind = `subject:${d.subject}`;
        }
      }
      try {
        window.__lxsRecordMessage({
          receiver: window.location.origin, top: window.top === window, sender: event.origin, kind, keys, dataKeys, state,
        });
      } catch (e) {
        // Binding not ready.
      }
    }, true);
  });

  /**
   * Settle and collect the final state.
   *
   * @param {import('@playwright/test').Page} page The embedding page.
   * @returns {Promise<Object>} The trace.
   */
  rec.finish = async (page) => {
    const consumer = page.locator('[data-region="local_ltixsembed-notice"][data-role="consumer"]');
    const consumerState = (await consumer.count()) ? await consumer.evaluate((el) => ({
      current: el.getAttribute('data-lxs-current'),
      peer: el.getAttribute('data-lxs-peer'),
      fallback: el.getAttribute('data-lxs-fallback'),
      retries: el.getAttribute('data-lxs-retries'),
      rejected: el.getAttribute('data-lxs-rejected'),
      noticeVisible: !el.querySelector('[data-region="state-message"]').classList.contains('d-none'),
      cid: (el.querySelector('input[name="cid"]') || {}).value || '',
    })) : null;
    const frame = page.frames().find((f) => f.parentFrame() === page.mainFrame() && f.url().startsWith(b));
    let frameState = null;
    for (let attempt = 0; frame && attempt < 5 && (frameState === null || frameState.path === 'unreadable'); attempt++) {
      if (attempt) {
        await new Promise((resolve) => setTimeout(resolve, 1000));
      }
      await frame.waitForLoadState('load').catch(() => {});
      frameState = await frame.evaluate(() => {
        const el = document.querySelector('[data-region="local_ltixsembed-notice"][data-role="provider"]');
        return {
          path: window.location.pathname,
          userId: Number(window.M && window.M.cfg ? window.M.cfg.userId : 0),
          provider: el ? {
            current: el.getAttribute('data-lxs-current'),
            channel: el.getAttribute('data-lxs-channel'),
            noticeVisible: !el.querySelector('[data-region="state-message"]').classList.contains('d-none'),
          } : null,
        };
      }).catch(() => ({ path: 'unreadable', userId: 0, provider: null }));
    }
    const popups = page.context().pages().slice(pagesAtStart).filter((p) => p !== page);
    const toplevel = [];
    for (const p of popups) {
      toplevel.push(await p.evaluate(() => ({
        origin: window.location.origin,
        path: window.location.pathname,
        userId: Number(window.M && window.M.cfg ? window.M.cfg.userId : 0),
        framed: window.top !== window,
      })).catch(() => ({ origin: '', path: 'closed', userId: 0, framed: false })));
    }
    const bHost = new URL(b).hostname;
    const cookies = (await page.context().cookies()).filter((c) => bHost.endsWith(c.domain.replace(/^\./, '')))
      .map((c) => ({ name: c.name.replace(/[A-Za-z0-9]{8,}$/, '<id>').replace(/state-[A-Za-z0-9-]+/, 'state-<id>'),
        sameSite: c.sameSite, secure: c.secure, partitioned: Boolean(c.partitionKey) }));
    // Success = a signed-in page of the tool that is not one of its sign-in or launch endpoints (the course page, or the
    // published activity when the tool resource is an activity).
    const toolPage = (path) => /^\/(course|mod\/[a-z0-9_]+)\/view\.php$/.test(path);
    const iframeOk = Boolean(frameState && toolPage(frameState.path) && frameState.userId > 2);
    const toplevelOk = toplevel.some((t) => t.origin === b && toolPage(t.path) && t.userId > 2 && !t.framed);
    return {
      counts: { ...rec.counts },
      cookiesB: cookies,
      consumer: consumerState,
      frame: frameState,
      toplevel,
      // A top-level window is NEVER counted as an embedded success: the two outcomes are recorded separately.
      outcome: { iframe: iframeOk ? 'success' : 'failed', toplevel: toplevel.length ? (toplevelOk ? 'success' : 'failed') : 'none' },
      messages: rec.messages.slice(),
      ms: Date.now() - rec.started,
    };
  };
  return rec;
}

/**
 * Store a trace: as a test attachment and as one JSON line in the evidence file.
 *
 * @param {Object} testInfo Playwright test info.
 * @param {string} name Trace name.
 * @param {Object} trace The trace.
 */
async function saveTrace(testInfo, name, trace) {
  const path = require('path');
  const dir = runDir();
  const line = { name, project: testInfo.project.name, when: new Date().toISOString(), ...trace };
  fs.appendFileSync(path.join(dir, 'traces.jsonl'), `${JSON.stringify(line)}\n`);
  await testInfo.attach(`trace-${name}`, { body: JSON.stringify(line, null, 2), contentType: 'application/json' });
}

module.exports = {
  SEED_PATH, stackAvailable, loadSeed, getUser, login, sql, setConfig, unsetConfig, blockFrameStorage, newRealBlockingContext,
  framesOf, singleSiteEnv, PLUGIN_SETTINGS, siteOf, learner, snapshotStack, snapshotStackOnce, restoreStack, runDir, putResult, getResult, MODES, applyMode, recordLaunch, saveTrace,
};
