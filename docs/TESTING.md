# Testing

## Layers

| Layer | Where | Run | Needs |
| --- | --- | --- | --- |
| JS cores and static security rules (Node `node:test`) | `tests/js/*.test.mjs` (incl. `security_static.test.mjs`) | `make test-js` or `npm test` | Node 20+ |
| PHPUnit unit | `tests/local`, `tests/external`, `tests/privacy`, `tests/install_test.php` (fresh install, release), `tests/upgrade_test.php` (0.2.0 -> 0.3.0) | `ltirun <45\|53> <pgsql\|mariadb> local_ltixsembed phpunit` | Moodle tree |
| PHPUnit integration and gate evidence | `tests/hook_callbacks_test.php`, `tests/gates_test.php` (real core flows: hook dispatch, OIDC login URL, header sending, cookie helper) | same | Moodle tree |
| Behat (non-JS) | `tests/behat/ltixsembed.feature` (9 scenarios, last one `@javascript`) | `ltirun <tree> <db> local_ltixsembed mirror` then `ltibehat <45\|53> <pgsql\|mariadb> local_ltixsembed` | Behat site. Results per combo in docs/ACCEPTANCE.md; the `@javascript` scenario is not run locally. |
| Playwright | `tests/playwright/*.spec.js` | see tests/playwright/README.md | live sites |
| Static | phplint, validate, savepoints, mustache, codechecker (0 warnings), phpdoc | `ltirun ... static` | Moodle tree |

Full run: `env -u JAVA_TOOL_OPTIONS ltirun <45|53> <pgsql|mariadb> local_ltixsembed all`. The `env -u JAVA_TOOL_OPTIONS` is needed because JVM startup noise on stderr breaks the mustache check.

AMD: sources in `amd/src`, build with `make amd-build` (babel and terser through `tools/amd_build.cjs`, `npm install` first). The built files in `amd/build` must be rebuilt after every source change.

## Playwright specs

| Spec | Protocol | Browser state |
| --- | --- | --- |
| `single-site.spec.js` | UI + security (settings, test page, audit, fallback refusals) | default |
| `two-site-bridge.spec.js` | UI + security (detection, fallback to B, forged messages, reload/back, expired session on A, embedded state) | emulated no-storage frame; real blocking for one case |
| `session-lifecycle.spec.js` | CE-01 (cookie matrix, lifecycle launch/navigation/reload/expiry/relaunch/logout) and Storage Access API path | REAL Chromium blocking (`e2e.newBlockingContext`); SAA path emulated (stubbed API, no storage) |
| `security-matrix.spec.js` | CE-02 security protocol (foreign embedding origin hears nothing, no secrets in URLs, no plugin framing headers) | default |
| `accessibility.spec.js` | FAM-04/CE-04 a11y: axe-core WCAG 2.0/2.1/2.2 A+AA on every plugin region (platform notice, tool notice, test page, audit page), live region, keyboard-only fallback, named controls | emulated no-storage frame |
| `modes.spec.js` (0.3.0) | CE-02: modes none/consumer/provider/cooperative x conditions default/blocked3pc/nostorage, traces, "never worse together" bound, fallback recorded separately, peerbridge required/auto, automatic mode | default; REAL blocking; emulated no-storage |
| `browser-conditions.spec.js` (0.3.0) | CE-03: launch cookies per mode under real blocking, real Storage Access API grant, CSP frame-ancestors, X-Frame-Options, session expiry + relaunch | REAL blocking; default |
| `postmessage-audit.spec.js` (0.3.0) | CE-04: delivered-message audit, replay/tamper/expiry/context/sender rules in the browser, third origin | emulated no-storage frame; default |

Traces: every two-site run writes JSON lines to `$LXS_EVIDENCE_DIR/run-<id>/traces.jsonl` (default under the OS temp
dir) and attaches them to the test. A trace holds counts (OIDC logins on B, auth requests on A, tool launch POSTs,
frame redirects, OIDC state cookies, tool session cookies, windows), the tool cookies with SameSite/Secure/partitioned,
every delivered postMessage (receiver, sender, kind, key names), the runtime attributes of both notices and the
outcome `{iframe, toplevel}`: a top-level fallback is never counted as an embedded success.

Stack hygiene: every spec snapshots this plugin's settings on A and B, both sites' `allowframembedding` and the
activity's launch container once per run (`snapshotStackOnce`) and restores them in `afterAll` (`restoreStack`, asserted).
Modes are switched with the plugin's own role switches, never by uninstalling. Runs hold `/tmp/e2e_stack.lock` and the
pgsql tree locks of both sites (no upgrade by another agent during a run).

All browsers: `LXS_ALL_BROWSERS=1` (projects only for installed browsers), `NODE_EXTRA_CA_CERTS=<stack CA>` (the
`nostorage` emulation fetches frame requests from Node), `LTI_SEED=.../seed-pair-45.json|seed-pair-53.json` for the
same-version pairs A->A2 / B->B2. Reverse mixed direction (B 5.3 platform -> A 4.5 tool): build a seed with
local_lticompletionsync's fixture (`buildScenario(seed, {platform: 'b', tool: 'a', scenario: 'xsr', learner:
'xsembedstudent'})` from its `tests/playwright/lib.js`; shape like the pair seeds: roles a = platform, b = tool, with
`sites.{a,b}.id`) and pass it as `LTI_SEED`; success is any signed-in tool page (`/course/view.php` or
`/mod/<x>/view.php`). Run against the stack (the stack's wrapper, so env and proxy bypass are right):

```bash
/home/claude/e2e/bin/e2e-test -c /home/claude/work/moodle-local_ltixsembed/tests/playwright/playwright.config.js
```

The specs only change this plugin's settings and the seeded activity's `launchcontainer` (restored in `afterAll`); they never re-seed. The lifecycle test deletes only the B sessions of the B user linked to `studentA2`.

## Browser matrix (CE-03)

| Browser | Status |
| --- | --- |
| Chromium (Playwright `channel: chromium`, real "block third-party cookies" profile setting) | automated tests (local) |
| Chromium headless shell, default cookie settings | automated tests (local) |
| Firefox (Playwright build): default Total Cookie Protection, and REAL `network.cookie.cookieBehavior=1` | automated tests (local, 0.3.0); runs with `ignoreHTTPSErrors` (its profile does not read the system CA of the local stack) |
| WebKit (Playwright build, Linux): blocks third-party cookies by default = real no-frame-storage browser | automated tests (local, 0.3.0), green; an intermittent WebKit internal error on the platform's own frame request is retried once by the harness (ACCEPTANCE "WebKit harness finding"). Not Safari (no ITP heuristics, no prompts) |
| iPhone 14 viewport on WebKit (`mobile-safari`) | automated test (local): core launches without iframe on mobile, plugin adds nothing. Not a device |
| iOS Safari on a real device | **not possible here** |
| Real Storage Access API in Chromium | automated tests (local): refused without gesture/permission; with the permission granted (as the prompt would) and a click, `requestStorageAccess()` resolves and unpartitioned cookies become usable (`browser-conditions.spec.js`). The prompt UI itself is not driven |
| Real Storage Access API prompts in Firefox/Safari | **not verified** |
| iOS/Safari on real devices | **not possible here** (no device, no macOS); declared, not emulated as passed |

"Emulated" means route interception plus an init script that removes the frame's cookie storage; it reproduces the effect of a fully blocking browser on Moodle B but is not that browser.

## Developer-debugging page check (release gate F6)

Every page of the plugin is loaded over HTTP on an isolated fresh site (own database, not the shared stack) with `debug=DEVELOPER`, `debugdisplay=1` and the default cURL block list (which blocks the site's own address 127.0.0.1): settings page, `test.php`, `audit.php`, `fallback.php` by GET, and `mod/lti/view.php` of an embedded activity (consumer notice). A page passes with the expected HTTP status (200; 405 for the GET on `fallback.php`), a signed-in page, no Whoops page, no `debuggingmessage`/`notifytiny` output, no PHP notice/warning/deprecation markup, and a PHP server log free of notices. The same check with the block list emptied proves that the self-check still fetches and evaluates the headers. Result: docs/ACCEPTANCE.md, section F6.

PHPUnit counterpart: `config_audit_test::test_audit_page_with_blocked_own_host_under_developer_debugging` and `::test_real_fetcher_honours_the_curl_blocklist` (both `assertDebuggingNotCalled`).

## Conventions

PHPUnit 9 docblock style (`@covers`, `@dataProvider`), no attributes, so the same files run on 4.5 (PHPUnit 9) and 5.x (PHPUnit 11, deprecations accepted).

## CI workflows

`.github/workflows/moodle-plugin-ci-dev.yml` (branches except main, reduced matrix 4.5/5.0/5.3) and
`moodle-plugin-ci-main.yml` (main, full matrix 4.5 to 5.3 x PostgreSQL/MariaDB, release artefact, coverage floor, stale
files) and `playwright.yml` (manual/weekly: one Moodle 4.5 site, the plugin alone, `seed.php` exports the `LXS_*` variables, `npm install` + `npm test`; the two-site specs skip without the stack). None has run (no GitHub access). Checked locally 2026-10-25 with `/home/claude/bin/ltici-check.py` (PyYAML
parse, job graph and `needs`, matrix cells, Behat tag, archive prefix, every referenced plugin path, no clone of plugin
repositories): 0 errors for all five family plugins. No workflow clones another plugin repository (the template's sibling
lockstep job was removed: the family plugins are independent).
