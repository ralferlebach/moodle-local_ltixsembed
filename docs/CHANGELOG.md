# Changelog

## 0.3.0 (alpha, version 2026102700) - family audit 2026-10-27, items CE-01 to CE-04

Upgrade: real upgrade step from 0.2.0 (2026102502). It writes the role switches explicitly (a missing value was read
as "on", so the effective mode does not change) and adds the new setting `peerbridge` = `auto`. There are no tables.
Still no upgrade path from 0.1.0 alpha (other component name; see 0.2.0).

### Added
* CE-02 operating modes: consumer-only (plugin active on the platform only), provider-only (active on the tool only)
  and cooperative (both). There are no new mode settings: a side is active through its role switches
  (`consumerenabled`, `providerenabled`) and inactive with both off, which adds nothing to any page. The capability
  test page shows the role of the site.
* Setting `peerbridge` (platform side): `auto` (default) treats a tool that never answers as normal, so a platform
  that embeds a tool WITHOUT this plugin suggests and opens nothing. `required` restores the 0.2.0 reading (silence
  means a blocked frame) for sites where every tool runs the bridge with "pages of LTI users" on.
* New pure module `amd/src/mode_core.js` with the rules of the three modes: only the platform opens a window, at
  most once per page load and only for a reported problem; the tool never opens or navigates a window; restarts only
  after a user grant and at most twice per page load; the tool hint changes between "reopen the activity"
  (provider-only) and "the page offers a new window" (cooperative).
* Runtime state in data attributes of both notices (`data-lxs-current`, `-peer`, `-fallback`, `-retries`,
  `-rejected`, `-lastreject`, `-channel`) so browser traces can tell an embedded success from a top-level fallback.
* CE-04 diagnostics hand-over: event `\local_ltixsembed\event\embed_state_reported` and external function
  `local_ltixsembed_report_state` (fixed vocabulary, signed-in users only, deduplicated and rate-limited per session
  through the new session cache `reports`). Both events of the plugin carry `other.correlationid` (SHA-256 of the
  channel nonce, the same on both sites), `other.code` and `other.version`, the contract that `tool_ltidiagnostics`
  reads for every `local_lti*` component. No hard dependency.
* The tool's login page (`/login/index.php`) carries the provider bridge (inactive in a top-level window), so a
  session that expires inside the frame is reported to the platform as `session_lost`.
* Tests: PHPUnit `upgrade_test`, `external/report_state_test`, `local/diagnostics_handover_test` (contract plus the
  real reader of `tool_ltidiagnostics` where installed), inactive and one-sided hook tests; Node `mode_core.test.mjs`
  and new static rules; Playwright `modes.spec.js` (4 modes x 3 browser conditions with traces and the "never worse
  together" bound), `browser-conditions.spec.js` (cookies per mode under real blocking, real Storage Access API grant
  in Chromium, CSP frame-ancestors, X-Frame-Options, session expiry and relaunch), `postmessage-audit.spec.js`
  (delivered-message audit, forged/replayed/expired/wrong-context/wrong-sender messages, third origin) and axe-core
  WCAG checks in `accessibility.spec.js`. Behat scenarios for the new setting, the mode display and the inactive switch.

### Changed
* Default behaviour of the platform with a silent tool (see `peerbridge`): no longer `blocked_frame`, now the
  consumer-local state `no_peer` without notice. Upgrade sites get `auto`; choose `required` to keep the old reading.
* Plain-language reasons in the notices (what happened, why, that no browser setting needs to change).
* Privacy note and `privacy:metadata`: problems and fallbacks are recorded in the site log (user, status word, random
  attempt number); the plugin still keeps no personal data of its own (null provider).
* `fallback_launched` keeps `other.reason` and adds the diagnostics fields.
* Playwright specs snapshot and restore every setting they change on the shared stack (`snapshotStackOnce`,
  `restoreStack`); Firefox/WebKit projects are only created when those browsers are installed.

### Fixed
* Consumer-only operation was worse than no plugin: a platform embedding a tool without this plugin reported
  `blocked_frame` after the handshake timeout and, in automatic mode, opened a second window after a successful
  embedded launch. Fixed by `peerbridge` = `auto` (see above).
* Documentation said the role switches were off by default; they are on.

* Session expiry inside the frame was not detected under real third-party cookie blocking: Chromium then also refuses
  `sessionStorage` in the frame, so the "was signed in" marker never existed. The tool's login page (rendered for a
  visitor who is not signed in) now reports `session_lost` by itself (`loginpage` flag, `detectSessionLost`).
* Audit page: the "Info" badge (`bg-info text-dark`) failed WCAG colour contrast (axe-core); now `bg-light text-dark border`.
* Test infrastructure: the `nostorage` emulation (`blockFrameStorage`) used `route.fetch()`, which re-added the browser
  context's cookies and stored Set-Cookie, so HTTP cookies leaked into "storage-less" frames and launches succeeded.
  Every frame request now goes through a fresh, empty API context. WebKit (which blocks third-party cookies natively)
  no longer uses the emulation.
* Playwright harness (WebKit): an intermittent "WebKit encountered an internal error" on the platform's own frame request
  (independent of the plugin, 0 of 16 isolated launches with or without it) is retried once, recorded as `harnessRetry`;
  the silence test measures its own plugin-free baseline instead of assuming success. Reverse direction B 5.3 -> A 4.5
  runs with a seed built from local_lticompletionsync's E2E fixture; tool pages other than the course page count as
  embedded success.
* Playwright config: per-project launch options (Chromium proxy bypass, Firefox direct connection and
  `ignoreHTTPSErrors` for the local CA); specs run against pair seeds (`LTI_SEED=seed-pair-45/53.json`).

### Removed
* Nothing.

## 0.2.0 (alpha, version 2026102502)

### Post-rename cleanup (2026-10-25, version unchanged)
* Internal abbreviation derived from the old name replaced: `lce`/`LCE_*` -> `lxs`/`LXS_*`: the bridge message envelope key (`lxs: 1`), data attributes `data-lxs-*` in templates and JS, the sessionStorage marker `lxs.signedin`, the probe cookie and storage names `lxs_probe*`, the nonce HMAC domain prefix `lxs1`, Playwright/seed variables `LXS_*` (`LXS_BASE_URL`, `LXS_ALL_BROWSERS`, ...). AMD build files regenerated with `tools/amd_build.cjs` (the unchanged sources rebuilt byte-identical first). Both sites must run this build: a site with the old build does not understand the new envelope key (fresh install only, so no mixed state exists).
* CI workflows: removed the template job `ecosystem-lockstep`, which cloned `moodle-{auth,enrol,mod,tool}_<name>` repositories that do not exist and failed every tagged build; `maturity-gate` no longer waits for it. The family plugins are independent and versioned separately.
* Playwright workflow: removed the sibling-plugin clone step (`moodle-{auth,mod,tool}_ltixsembed`, nonexistent, and all copied to `local/ltixsembed`); the plugin is installed alone. `npm ci` replaced by `npm install` (there is no lock file in `tests/playwright`); `LCE_BASE_URL` -> `LXS_BASE_URL`.
* CI workflows: the reduced and full matrices (4.5 to 5.3, PostgreSQL and MariaDB) are unchanged. The dev pipeline's code checker runs without an installed Moodle and now gets the plugin path (`moodle-plugin-ci codechecker ... plugin`); without it the blocking step stopped with "Not enough arguments (missing: plugin)". Header comments state the real matrix; template wording about sibling plugins was removed. Validated with a YAML parser and a path/name check (see TESTING.md).

Renamed (owner decision 2026-10-25)

* Renamed from `local_lticrossembed` (install path `local/lticrossembed`) to `local_ltixsembed` (install path `local/ltixsembed`); no upgrade path from 0.1.0 alpha. 0.2.0 is a fresh install: uninstall the old plugin (this deletes its settings) and install this one. Changed with the name: settings `local_ltixsembed/*`, capability `local/ltixsembed:viewdiagnostics`, cache definition, external function `local_ltixsembed_issue_nonce`, AMD modules `local_ltixsembed/*` (build files regenerated with `tools/amd_build.cjs`), templates `local_ltixsembed/*`, CSS classes `local_ltixsembed-*`, language files, namespaces, URLs `/local/ltixsembed/...`, Behat tag `@local_ltixsembed`.
* `db/upgrade.php` has no steps any more; `tests/upgrade_test.php` (0.1.0 path) was replaced by `tests/install_test.php` (no-op upgrade function, setting defaults of a fresh install, release information).

Added

* Storage Access API recovery (CE-01 mitigation): where the browser offers `document.requestStorageAccess()` and the tool frame cannot keep a session (state `needs_user_intervention`), the tool-side notice shows a button. After a user grant the tool sends a new, strictly validated `retry` message and the platform restarts the launch from the `src` it rendered itself (at most twice per page load). Setting `storageaccess` (default on) switches the button off.
* New message type `retry` in the bridge protocol: fixed payload `{reason: "storage_access_granted"}`, channel nonce, context, expiry and replay checks like every other typed message.
* Playwright: `session-lifecycle.spec.js` (cookie matrix and session lifecycle under REAL Chromium third-party cookie blocking; Storage Access API path with an emulated browser), `security-matrix.spec.js` (foreign embedding origin, no secrets in URLs, no framing headers from the plugin), `accessibility.spec.js` (structural, keyboard only).
* Node: `tests/js/security_static.test.mjs` (no `postMessage` to `*`, validate-before-use, retry handler uses nothing from the message) and `retry` cases in `bridge_core.test.mjs`.
* Behat scenario for the new setting.

Changed

* Release 0.2.0, version 2026102502. Status wording in docs/ACCEPTANCE.md follows the five classes (code present / automated tests (local) / CI passed / functionally accepted / not verified); CI has not been executed.
* The documentation now says explicitly that the plugin is a diagnosis and fallback tool plus a Storage Access API recovery path, not a cookie-independent embedded session (CE-01 boundary).

Fixed

* Release gate finding F6 (low): `/local/ltixsembed/audit.php` answered HTTP 500 under developer debugging when the site's own address is on the cURL block list (`curlsecurityblockedhosts`). Root cause: the self-check handed the blocked URL to `\curl::head()`; core refused it and triggered `\core\event\url_blocked`, whose validation always calls `debugging()`, which the developer error handler turns into an error page. Fix: `header_fetcher` asks the cURL security helpers (core plus plugin `curl_security_helper` callbacks) BEFORE any request and follows redirects itself (same origin only, each hop pre-checked, max. 3), so a blocked URL never reaches `\curl`. The audit shows the new explanatory finding `fetch_blocked` (no advice to relax the block list). `audit.php` builds its context through the new testable `config_audit::page_context()`.
* `fallback.php` opened with GET (reload, back button, bookmark) now shows a normal page with a notice and a "Back to the activity" button (HTTP 405; no extra header, the plugin emits none - `gates_test::test_g3_plugin_adds_no_headers`) instead of throwing an exception, which under developer debugging was an error page. POST refusals (sesskey, token, capability) still throw as before.
* Playwright: logout and relaunch, session expiry on the tool side and teardown races are covered by tests that previously did not exist.

Removed

* Nothing.

Privacy: unchanged, still no personal data stored (the new setting is a boolean).

## 0.1.0 (alpha, version 2026102401)

* Browser capability detection (AMD) with five states and an end-user notice with privacy note.
* postMessage bridge with strict validation core (origin allow-list, source, envelope, type, nonce, context, expiry, replay) and PHP one-time token issuance.
* Top-level window fallback endpoint (POST, sesskey, capability, fixed target, replay-protected token).
* Read-only configuration audit with SSRF-safe self-fetch of the emitted headers.
* Hook injection on mod_lti view (consumer) and auth_lti/enrol_lti launch pages (provider).
* Evidence tests for gates CE-G1..G3; CE-G4 (real browsers) not verified.
* Privacy: null provider, justified in docs/PRIVACY.md.
* Template load-test harnesses removed (out of scope).
