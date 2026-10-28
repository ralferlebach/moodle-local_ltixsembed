# Acceptance status local_ltixsembed 0.3.0 (alpha, version 2026102700), 2026-10-27

Basis: family audit 2026-10-27 (development branches), items CE-01 to CE-04 and FAM-00..05 as far as this plugin is
concerned. Status classes: **code present** / **automated tests (local)** / **CI passed** / **functionally accepted** /
**not verified** / **core gate open**.

* **CI passed: not executed by us.** GitHub `development` still holds the uninitialised template; this implementation
  replaces it when the owner pushes it. The owner's development CI on that SHA is the separate, binding gate.
* **Functionally accepted: nothing.** The owner has not accepted anything.
* "automated tests (local)" = executed on the development machine on 2026-10-27 against the code in this directory.
  Skipped tests are never counted as passed.
* The 0.2.0 acceptance record (2026-10-25) is superseded; its findings that still hold are repeated below.

## Local test runs (2026-10-27)

Playwright on the two-site stack (A = 4.5 platform, B = 5.3 tool, A2 = 4.5 tool, B2 = 5.3 tool; all runs under
`/tmp/e2e_stack.lock` plus the pgsql tree locks of both sites; settings snapshotted and restored, restore verified):

| Browser / seed | Specs | Result |
| --- | --- | --- |
| Chromium, A -> B (mixed 4.5 -> 5.3) | all 8 specs | 2026-10-27 run 1: 46 passed, 8 failed (wrong count expectations of the new specs, one axe contrast finding), 1 skipped (single-site "outsider": no such user on the stack). After the fixes the three affected files were re-run: **31 passed, 0 failed** |
| Chromium, A -> A2 (same version 4.5) | `modes`, `postmessage-audit` | **22 passed** |
| Chromium, B -> B2 (same version 5.3; B acts as platform) | `modes`, `postmessage-audit` | `modes` **all passed** (run 1: 20 passed, the 2 failures were `postmessage-audit` with a hard-coded learner); `postmessage-audit` re-run after the fix: **3 passed** |
| Firefox 1543 (Playwright build), A -> B | `modes`, `postmessage-audit` | **every Firefox test passed** (the 10 failures of that run were all WebKit, see next row) |
| WebKit 2359 (Playwright build), A -> B | `modes`, `postmessage-audit` | `postmessage-audit` passed; `modes` final run 2026-10-28: **9 passed, 0 failed**, 11 skipped (conditions WebKit does not have: no third-party switch in Playwright WebKit, `nostorage` = WebKit's `default`, mobile-only test). Earlier red test analysed and fixed, see "WebKit harness finding" below |
| Chromium, B -> A (mixed 5.3 platform -> 4.5 tool, reverse registration built with local_lticompletionsync's E2E fixture `buildScenario`, scenario `xsr`, tool resource = a page activity) | `modes` | **19 passed, 0 failed**, 1 skipped (mobile-only test) |
| WebKit, iPhone 14 viewport (`mobile-safari`), A -> B | `modes` (mobile test) | **1 passed**: on a mobile device core mod_lti launches in the same window - no iframe, the plugin adds nothing |
| Safari on iOS (real device) | - | **not possible here** |

| Layer | Result |
| --- | --- |
| Node (`node --test tests/js/*.test.mjs`) | 72 of 72 pass (incl. `mode_core.test.mjs`, new static rules) |
| `ltirun 45 pgsql all` (4.5, PostgreSQL 16, PHP 8.3) | static steps OK; PHPUnit **208 tests, 1977 assertions, OK** (final run 2026-10-28) |
| `ltirun 45 mariadb all` (4.5, MariaDB 11.4) | static steps OK; PHPUnit **208 tests, 1977 assertions, OK** |
| `ltirun 53 pgsql all` (5.3, PostgreSQL 17) | static steps OK; PHPUnit **208 tests, 1984 assertions, OK**, 26 PHPUnit deprecations (final run 2026-10-28) |
| `ltirun 53 mariadb all` (5.3, MariaDB 11.4) | static steps OK; PHPUnit **208 tests, 1964 assertions, OK**, 26 PHPUnit deprecations (docblock metadata, accepted) |
| `ltirun 50 pgsql all` (5.0) | static steps OK; PHPUnit **208 tests, 1978 assertions, OK**, 26 deprecations |
| `ltirun 51 pgsql all` (5.1) | static steps OK; PHPUnit **208 tests, 1978 assertions, OK**, 26 deprecations |
| `ltirun 52 pgsql all` (5.2) | static steps OK; PHPUnit **208 tests, 1978 assertions, OK**, 26 deprecations |
| Static steps in every cell | phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings |
| Behat non-JS | `ltibehat 45 pgsql`: **11 scenarios, 11 passed** (all non-JS scenarios incl. the three new ones); `ltibehat 53 pgsql`: **11 scenarios, 11 passed**; `@javascript` scenario not run locally |
| Full 24-cell matrix (`ltifullmatrix`) | not run by this agent (lead) |

## CE-01 [P0] Installable plugin, real functional tests on 4.5-5.3

| Item | Status | Evidence |
| --- | --- | --- |
| Correct component, no template placeholders, version 2026102700, release 0.3.0, requires 4.5, supported [405, 503], no hard dependency | automated tests (local) | `version.php`; `install_test::test_version_file_declares_release_0_3_0` |
| Fresh install on 4.5, 5.0, 5.1, 5.2, 5.3 (PostgreSQL) and 4.5, 5.3 (MariaDB) | automated tests (local) | every PHPUnit site installs the plugin from scratch (table above); `install_test::test_fresh_install_defaults` |
| Real upgrade 0.2.0 (2026102502) -> 0.3.0 | automated tests (local) | `db/upgrade.php:34-53`; real run on the 4.5 and 5.3 PostgreSQL sites that were at 2026102502 (= stack sites A and B): `upgrade_log` holds the 2026102700 savepoint on both, stored values afterwards `peerbridge=auto`, role switches explicit, all other values unchanged. `upgrade_test` (4 tests: unset switches, chosen values kept, inactive site stays inactive, idempotent) |
| Negative control (mutation) | executed once | removing the `peerbridge` write in the step turns `upgrade_test::test_upgrade_from_020_with_unset_switches` RED (4.5 MariaDB); restored, green |
| Real functional tests per version | automated tests (local) | PHPUnit with real mod_lti activities, real hook dispatch, real external API, real log store on 4.5-5.3; Playwright on real 4.5 and 5.3 sites (CE-02..04) |
| Uninstall | automated tests (local, 0.2.0 evidence, unchanged mechanism) | 0.2.0 scratch-DB uninstall left nothing but core-rebuilt cache definitions; 0.3.0 adds a session cache and an event, both handled by core on uninstall. Not re-run for 0.3.0 |
| Development CI green on the pushed SHA | not verified | not executed by us (owner) |

## CE-02 [P0] Three independent operating modes, no interference

Modes are switched with the plugin's own role switches (both off = inactive), never by uninstalling.

| Item | Status | Evidence |
| --- | --- | --- |
| Inactive switch: an inactive site adds nothing (no markup, script, token) to platform or tool pages | automated tests (local) | `hook_callbacks_test::test_inactive_site_adds_nothing`, Behat "An inactive site adds nothing", E2E: no notice/no bridge message from an inactive side in every mode trace |
| Each side works without the other | automated tests (local) | `hook_callbacks_test::test_each_side_works_without_the_other`; E2E below |
| Consumer-only never worse than no plugin (silent tool = `no_peer`, nothing suggested/opened) | automated tests (local) | `mode_core.js:54` (`silenceState`), `bridge.js:190` (`arm`); `mode_core.test.mjs`; `modes.spec.js` |
| Cooperative never worse than the better one-sided run: no extra redirect, OIDC login, auth request, state cookie, session cookie or window | automated tests (local) | `mode_core.js:73,84` (`shouldAutoOpen`, `mayRestart`); Node table test "never worse together"; `modes.spec.js` per-mode equality to the plugin-free baseline and the per-condition bound test |
| Mixed versions (A 4.5 platform -> B 5.3 tool) and same-version pairs (A 4.5 -> A2 4.5, B 5.3 -> B2 5.3) | automated tests (local, Chromium) | `modes.spec.js` + `postmessage-audit.spec.js` with `LTI_SEED=state/seed-pair-45.json` (A -> A2) and `seed-pair-53.json` (B -> B2); the default seed is the mixed pair A 4.5 -> B 5.3. the reverse mixed direction B 5.3 platform -> A 4.5 tool with a seed built from local_lticompletionsync's fixture (`docs/TESTING.md`) |
| Negative controls (mutation) | executed once | `silenceState` always `blocked_frame` (0.2.0 behaviour): `mode_core.test.mjs` RED (2); auto-open without once-per-page guard: RED (2); restart for any sender: RED (1); a `window.open` in the tool side: `security_static.test.mjs` RED (1). E2E: `peerbridge=required` reproduces the 0.2.0 reading (`blocked_frame` on a silent tool), asserted in `modes.spec.js` |
| Fixed (0.2.0 bug found by this item) | automated tests (local) | 0.2.0 reported `blocked_frame` after the timeout whenever the tool did not run the bridge (consumer-only, or a cooperative tool after a successful launch with `providerltipages` off), and the automatic mode then opened a second window after a working embedded launch |

### E2E traces (Chromium, two-site stack)

Full table (100 launches): [docs/evidence/E2E_TRACES_0.3.0.md](evidence/E2E_TRACES_0.3.0.md). Summary, cooperative vs.
the plugin-free baseline of the same browser and condition (OIDC login req. / auth req. / launch POST / frame redirects
/ state cookies / windows -> platform state, tool state / channel -> outcome iframe / top-level):

| Pair / browser / condition | baseline "none" | cooperative |
| --- | --- | --- |
| A->B Chromium default | 2/1/1/3/1/0 -> embedded | 2/1/1/3/1/0 -> `embedded`, `embedded`/open -> embedded |
| A->B Chromium real blocking (CHIPS) | 2/1/1/3/1/0 -> embedded | identical -> embedded |
| A->B Chromium no frame storage (emulated) | 2/0/0/1/0/0 -> failed | identical -> `storage_unavailable` suggested -> failed (fallback row: top-level success, 1 window) |
| A->B Firefox default (TCP) | 2/2/1/3/1/0 | identical -> `embedded`/open -> embedded |
| A->B Firefox REAL reject-3P-cookies | 2/0/0/1/0/0 -> failed | identical -> `needs_user_intervention` suggested -> failed |
| A->B WebKit default (blocks 3P cookies) | 2/0/0/1/0/0 -> failed | identical -> `needs_user_intervention` suggested -> failed |
| A->A2 (4.5/4.5), B->B2 (5.3/5.3) and B->A (5.3 platform -> 4.5 tool), Chromium, all three conditions | same numbers as A->B Chromium | identical to their baselines |

In every row consumer-only and provider-only have the same counts as the baseline; consumer-only never shows a notice
on a silent tool (`no_peer`); a window opens only on a user click or, in automatic mode, once and only in cooperative
mode with a reported problem (`auto-*` rows).

Observation (recorded, not a plugin claim): in the Firefox `default` baseline run one launch was classified `failed`
because the frame could not be read at that moment although its counts equal the successful runs; the reader now
retries (support.js) and every later Firefox default run embedded.

### WebKit harness finding (analysed 2026-10-28)

The WebKit test "cooperative with peer bridge required/auto" was red twice, each time at the harness step that waits
for the tool frame. Root cause, from the retained Playwright trace: the iframe request to the PLATFORM'S OWN
`/mod/lti/launch.php` failed with "WebKit encountered an internal error" before any LTI or plugin step; the frame then
loaded A's login page as a guest, the new guest session cookie replaced the learner's session on A (sesskey changed),
and the page ended on A's login page. Controlled experiment: 16 WebKit launches in a row, 8 with the plugin inactive and
8 cooperative, all reached B (0 failures): intermittent WebKit network-process behaviour, independent of the plugin (2 in
about 30 WebKit test launches). Plugin defect: none. Harness fixes (`modes.spec.js` `run()`): (1) exactly that failure -
"WebKit encountered an internal error" on A's own `launch.php` - leads to one retry in a fresh context, recorded as
`harnessRetry` in the trace; any other failure, or a second one, stays red; (2) the test compared against an assumed
`success` when no baseline of the same run existed - wrong for WebKit, which blocks third-party cookies; it now
measures its own plugin-free baseline. Final WebKit run: green, the retry was not needed (`harnessRetry` null in every
trace).

## CE-03 [P0] Native sessions and browser negative cases

| Item | Status | Evidence |
| --- | --- | --- |
| Unpartitioned third-party cookies blocked (REAL Chromium setting): core's launch cookies on B (`MoodleSession`, `lti1p3_*`) are `SameSite=None; Secure; Partitioned` and the embedded launch works, in all four modes; the plugin adds no cookie | automated tests (local, Chromium) | `browser-conditions.spec.js` "real blocking, <mode>", traces `cookies-blocked3pc-*` |
| CHIPS | automated tests (local, Chromium) | as above (partitionKey present on every launch cookie) |
| Storage Access API, real Chromium | automated tests (local, Chromium): without a gesture `requestStorageAccess()` is refused and the unpartitioned cookie is blocked; after B was visited top-level, the permission was granted the way the prompt would (CDP `Browser.setPermission`, origin A, embedded origin B) and the user clicked in the frame, the call resolved, `hasStorageAccess()` became true and the unpartitioned cookie was usable. The prompt UI itself is not driven | `browser-conditions.spec.js` trace `saa-real-chromium` |
| Storage Access API recovery flow (button, `retry`, restart, bounded) | automated tests (local) with an EMULATED API | `session-lifecycle.spec.js` (stubbed API) |
| CSP `frame-ancestors` (header added by request interception = emulated server setting, enforcement by the real browser) | automated tests (local, Chromium): frame refused; `required`: `blocked_frame`, notice, new window reaches B's course top-level (`{iframe: failed, toplevel: success}`, 1 window); `auto`: `no_peer`, no notice, no window | `browser-conditions.spec.js` |
| X-Frame-Options (real Moodle setting `allowframembedding` off on B) | automated tests (local, Chromium): as CSP; top-level the plugin's window ends at core's "Open tool" page, whose link opens the course (2 windows, the second one core's) | `browser-conditions.spec.js`; core then shows its own "Open tool" link even top-level - a second window that is core's, not the plugin's |
| Session expiry inside the frame and relaunch (real blocking) | automated tests (local, Chromium, real blocking): launch `embedded`; B session deleted -> frame on `/login/index.php`, tool and platform `session_lost`, notice shown; relaunch from A embeds again with exactly one more launch | `browser-conditions.spec.js`; fix: the tool's login page carries the bridge and reports `session_lost` without storage (`bridge_config.php:46,149`, `capability_core.detectSessionLost`), because Chromium with real blocking also refuses `sessionStorage` in the frame (observed) |
| Platform storage (LTI postMessage storage) | not verified | only probed (`lti.capabilities`); Moodle core does not implement it as platform, so no peer supports it |
| A top-level fallback is never counted as an embedded success | automated tests (local) | every trace records `outcome: {iframe, toplevel}` separately; fallback tests assert `{iframe: failed, toplevel: success}` |
| Browser without any frame storage (Safari "block all cross-site cookies", Firefox strict) | EMULATED only | `blockFrameStorage`. 0.3.0 fix: the 0.2.0 emulation used `route.fetch()`, which re-added the browser context's cookies and stored Set-Cookie - HTTP cookies leaked and launches succeeded although the emulation claimed "no storage"; now every frame request goes through a fresh, empty API context. The 0.2.0 statements that rested on this emulation were about detection (JS level) and stay valid; launch outcomes under it are only valid from 0.3.0 |
| Firefox (Playwright build 1543) | automated tests (local) | default (Total Cookie Protection): the embedded launch works in every mode, cooperative reports `embedded`; REAL `network.cookie.cookieBehavior=1` (reject third-party cookies): core's launch fails in every mode, cooperative reports `needs_user_intervention` and suggests the new window, provider-only explains it in the frame, consumer-only stays silent (`no_peer`); the new window reaches the course top-level. Firefox runs without certificate check (`ignoreHTTPSErrors`): its Playwright profile does not read the system CA store of the local stack |
| WebKit (Playwright build 2359, Linux) | automated tests (local), green (harness finding analysed above) | blocks third-party cookies by default: core's launch fails in the frame in every mode, cooperative reports `needs_user_intervention` and suggests the new window (a real no-frame-storage browser; the emulation is not used for WebKit). Playwright WebKit is not Safari: no ITP heuristics, no Safari prompts |
| Safari on iOS / real devices | not possible here | no device and no macOS; declared, never emulated as passed |
| Core gates (unchanged): no extension point for OIDC state/nonce storage (CE-G1), no per-context session cookie attributes (CE-G2) | core gate open | `tests/gates_test.php` (4.5 + 5.3) |

## CE-04 [P1] Security, UX, diagnostics

| Item | Status | Evidence |
| --- | --- | --- |
| Origin, sender, type, nonce, context, expiry, lifetime and replay validation | automated tests (local) | Node `bridge_core.test.mjs` (per rule); in the real browser `postmessage-audit.spec.js` (replay, tampered nonce, expired, wrong context, over-long lifetime, second window of the tool origin, platform window - each dropped with exactly that rule, `data-lxs-lastreject`) |
| No unauthorised postMessage data flow | automated tests (local) | `postmessage-audit.spec.js`: every DELIVERED message recorded - only A<->B, B hears only `welcome` with empty data, A only `hello`/`state` (+ core-less `lti.capabilities` probe), only envelope keys and `state`/`facts`; a third origin framing the tool receives nothing and cannot open a channel. Mode traces: no message to or from a third origin |
| Security tests for the report endpoint | automated tests (local) | `report_state_test` (14 tests: vocabulary per role, malformed ids, capability, guest, disabled side, dedup, rate limit, sliding window, event validation); mutation "accept any code" RED (6), restored green |
| WCAG: axe-core (WCAG 2.0/2.1/2.2 A+AA incl. contrast) | automated tests (local, Chromium): **no violation** in the plugin regions after one fix (audit badge `bg-info text-dark` failed colour contrast; now `bg-light text-dark border`) | `accessibility.spec.js` (platform notice in a problem state, tool notice in the frame, capability test page, audit page; scoped to the plugin regions, page-wide core findings attached) |
| Keyboard | automated tests (local) | `accessibility.spec.js` (keyboard-only fallback opens the window; storage access button focusable and operable; outcome in a status region) |
| Screen reader (NVDA/JAWS/VoiceOver) | not verified | no screen reader on this machine |
| Understandable fallback reasons | code present, automated tests (local) | notices say what happened, why, and that no browser setting must change (lang en/de `state_*_msg`, `consumer_whattodo`, `provider_hint*`); visible-state assertions in `modes.spec.js` |
| Diagnostics hand-over to tool_ltidiagnostics (family prefix `local_lti*`) | automated tests (local) | events `fallback_launched`, `embed_state_reported` with `other.correlationid/code/version` (`diagnostics_handover.php:99`, `report_state.php:65`); `diagnostics_handover_test::test_diagnostics_reader_gets_the_events` runs the real `tool_ltidiagnostics` log-store reader in every tree where it is installed (all seven cells) and the contract restated otherwise; E2E: log rows on A carry the same correlation id as the page (`modes.spec.js` fallback tests) |
| No hard dependency on tool_ltidiagnostics | automated tests (local) | `$plugin->dependencies = []`; without it events simply stay in the log |
| A tool whose session is already gone cannot report | stated boundary | no unauthenticated logging by design; the platform reports these cases |

## FAM items (this plugin)

| Item | Status | Evidence |
| --- | --- | --- |
| FAM-00 branch policy documented | code present | README "Branch policy" |
| FAM-01 component/release map | code present | README, CHANGELOG 0.3.0, this file; component `local_ltixsembed`, path `local/ltixsembed`, release 0.3.0, version 2026102700, Moodle 4.5-5.3 |
| FAM-02 no lockstep, optional integrations degrade | automated tests (local) | no dependency; diagnostics optional (above) |
| FAM-03 Cross-Site Embed consumer-only, provider-only, cooperative in the family stack | automated tests (local, Chromium) | `modes.spec.js` on the stack where all family plugins are installed; no regression of navigation/session in the traces. AGS/Completion regression across modes: not verified by this agent |
| FAM-04 security/privacy/a11y | see CE-04, docs/SECURITY.md, docs/PRIVACY.md | |
| FAM-05 evidence separation | this file | local runs, CI (not executed), owner acceptance (none) kept apart |

## Earlier evidence that still holds (0.2.0, 2026-10-25)

* Release gate F6 (audit page under developer debugging) - fix and tests unchanged (`config_audit_test`).
* Gates CE-G1..G3 evidence (`gates_test.php`), fallback endpoint security (`fallback_test`), nonce/replay (`nonce_test`).
