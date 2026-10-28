# local_ltixsembed - documentation

## 1. Goal and result in one paragraph

Embed an LTI 1.3 tool (typically Moodle B) in an iframe of Moodle A without assuming unpartitioned third-party cookies. The gates CE-G1 and CE-G2 FAIL for a local plugin (evidence below), so a full iframe solution is **not fork-free proven**. The plugin delivers the documented fallback: reliable detection, a validated message bridge and a safe new-window launch.

## 2. Architecture

| Part | Files |
| --- | --- |
| Detection | `amd/src/capability_core.js` (pure classification, Node-tested), `capability.js` (browser probes), `diagnostics.js` (admin page) |
| Bridge | `amd/src/bridge_core.js` (validator, Node-tested), `bridge.js` (consumer and provider wiring), `classes/local/bridge_config.php` |
| Tokens | `classes/local/nonce.php` (HMAC, single use, MUC replay markers `db/caches.php`), `classes/external/issue_nonce.php` |
| Fallback | `fallback.php`, `classes/local/fallback.php`, event `\local_ltixsembed\event\fallback_launched` |
| Audit | `audit.php`, `classes/local/config_audit.php`, `classes/local/header_fetcher.php` |
| Injection | `db/hooks.php`, `classes/hook_callbacks.php` (core hook `before_standard_top_of_body_html_generation`) |
| Settings | `settings.php`, `classes/local/settings.php`, `classes/local/origins.php` |
| Modes (0.3.0) | `amd/src/mode_core.js` (pure rules, Node-tested), `settings::site_mode()`, `settings::peer_bridge()` |
| Diagnostics hand-over (0.3.0) | `classes/local/diagnostics_handover.php`, `classes/external/report_state.php`, event `\local_ltixsembed\event\embed_state_reported`, session cache `reports` |

### Detection states

`no_peer` (0.3.0, platform only, never sent over the bridge: the tool did not answer and the setting `peerbridge` is `auto`; no notice), `embedded` (storage works in the frame), `storage_unavailable` (cookie or localStorage round trip fails, partitioned round trip also fails), `session_lost` (the tool answered but its session is gone), `blocked_frame` (no handshake before the timeout: frame refused or script blocked), `needs_user_intervention` (Storage Access API present and a user gesture could unlock it). The classification is a pure function of boolean facts.

### Bridge handshake

(Message types: `hello`, `welcome`, `state`, and since 0.2.0 `retry`.)

1. The tool (child) sends `hello` with context `pending` to each allow-listed parent origin.
2. The platform (parent) validates it and answers `welcome` carrying a client-generated channel nonce.
3. The child sends `state` with that nonce, the context `cm:<id>`, `iat` and `exp` (max 60 s, 5 s skew).
4. The parent checks exact origin, `event.source`, strict envelope keys, type, nonce (constant-time compare), context, time window and a replay set (cap 1000, then flood protection). Anything else is dropped silently.

The PHP-issued token authorises only the fallback POST and is not part of the browser-to-browser channel.

### Operating modes (CE-02, since 0.3.0)

| Mode | Platform A | Tool B | What happens |
| --- | --- | --- | --- |
| consumer-only | consumer side active | plugin absent or inactive | A shows the always-available manual "new window" option. A cannot see inside B: silence is the normal case (`no_peer`), nothing is suggested or opened. A CSP violation on A's own page is still reported. |
| provider-only | plugin absent or inactive | provider side active | B detects in the frame whether it can keep a session and explains it in the frame, with the Storage Access API button where the browser offers one. Without a channel it never sends anything but `hello` (to allow-listed origins) and advises to reopen the activity. |
| cooperative | consumer side active | provider side active | B reports its state to A over the validated channel; A explains the reason and offers (or, in automatic mode, opens once) the new window; B shows a short hint that defers to A and keeps the permission button; a grant makes A restart the launch (max. 2 per page load). |

Rules that keep cooperation "never worse than one side alone" (`mode_core.js`, Node table test, Playwright bound test):
only the platform opens a window, once per page load at most and only for a problem the tool reported (or, with
`peerbridge=required`, for silence); the tool never opens or navigates a window; a restart needs a user grant; neither
side changes a cookie, a redirect or the OIDC flow. "Inactive" is the plugin's own switch (both role switches off) -
the hook then adds nothing to any page (PHPUnit `test_inactive_site_adds_nothing`, Behat).

### Diagnostics hand-over (CE-04, since 0.3.0)

`tool_ltidiagnostics` reads every `local_lti*` component from the log stores and takes over `other.correlationid`,
`other.code`, `other.version` (and `connectionid`, unused here). This plugin triggers:

| Event | When | `code` |
| --- | --- | --- |
| `fallback_launched` | the learner (or the automatic mode) used the new window | `manual`, or the reported state |
| `embed_state_reported` (platform, module context) | the platform applied a problem state, or restarted a launch after a grant | `storage_unavailable`, `session_lost`, `blocked_frame`, `needs_user_intervention`, `storage_access_retry` |
| `embed_state_reported` (tool, system context) | a signed-in tool user hit a problem state or answered the permission prompt | `storage_unavailable`, `session_lost`, `needs_user_intervention`, `storage_access_granted`, `storage_access_denied` |

`correlationid` is the first 32 hex characters of SHA-256(`lxs-cid:` + channel nonce): platform and tool compute the
same value for one launch attempt, the nonce cannot be recovered from it. Reports go through
`local_ltixsembed_report_state` (login, capability `mod/lti:view` on the platform side, fixed vocabulary, 32-hex id,
deduplicated, at most 20 per session per 10 minutes). A tool whose session is already gone cannot report (no
unauthenticated logging by design); the platform reports those cases.

### Fallback

The notice contains a form (`method=post`, `target=_blank`, `rel=noopener`) with sesskey, cmid and a single-use token (HMAC over purpose, context, user, random and expiry; key derived from the Moodle encryption key; replay marker stores a hash only). `fallback.php` requires POST, `require_sesskey`, `get_course_and_cm_from_cmid`, `require_login`, verifies and consumes the token, logs the event and redirects to the fixed `/mod/lti/launch.php?id=<cmid>`. There is no redirect parameter, hence no open redirect, and no URL is fetched, hence no SSRF.

### Audit

Reads `allowframembedding`, `cookiesecure`, the core cookie helper's partitioning support and the headers of the site's own front page, fetched through `\curl` with the core security helper active (no URL parameter exists). It evaluates X-Frame-Options, CSP `frame-ancestors` and each Set-Cookie attribute. It reports; it never writes configuration.

Since 0.2.0 (release gate finding F6) every URL of the self-check is first tested with the cURL security helpers (core's `curl_security_helper` and those plugins add through the `curl_security_helper` callback, the same order as `\curl` uses). A blocked URL is never handed to `\curl`: otherwise core's `url_blocked` event calls `debugging()` and, under developer debugging, the page becomes an error page. Redirects are followed by the plugin, not by `\curl`: same origin as `wwwroot` only, every hop pre-checked, at most three. When the own address is blocked the audit shows the finding `fetch_blocked`, which explains the situation and points to the browser's developer tools; it never advises to relax the block list.

## 3. Gates and evidence

All in `tests/gates_test.php`, run on 4.5 and 5.3.

| Gate | Question | Result | Evidence |
| --- | --- | --- | --- |
| CE-G1 | Is there a no-fork extension point for OIDC state/nonce storage? | **No.** State lives in the cookie `lti1p3_<state>`, nonce in `$SESSION`; no hook, no callback, no platform storage in core. Full iframe without cookies is not fork-free proven. | `test_g1_*` |
| CE-G2 | Can session cookie attributes be set per context before headers? | **No.** The session starts before the first plugin hook (`after_config`), SameSite is fixed to Lax. What exists: core upgrades the session cookie (SameSite=None, Secure, Partitioned) for LTI launches itself. Post-hoc header rewriting through the core cookie helper is technically possible and deliberately not used (CE-P03: no blanket rewrite). | `test_g2_*` |
| CE-G3 | CSP and X-Frame-Options without fork? | **Partly.** X-Frame-Options is controlled solely by `allowframembedding`; core sends no CSP; hooks surround `send_headers`. The plugin emits no headers and reports the effective values in the audit. | `test_g3_*` |
| CE-G4 | Moodle A to B in Chrome, Firefox, Safari, iOS with blocking | **Partly.** Chromium with real third-party cookie blocking: the embedded launch works through core's partitioned session cookie. Firefox, Safari and iOS: not verified. A browser without any frame storage is emulated only. See docs/ACCEPTANCE.md. | `tests/playwright` |

## 3a. CE-01 - cookie / OIDC session gap: reproduction, mitigation, boundary

Reproduction (`tests/playwright/session-lifecycle.spec.js`, real Chromium with "block third-party cookies", A = Moodle 4.5 embeds B = Moodle 5.3, genuinely cross-site):

* A cookie that B's frame writes as `SameSite=None; Secure` (unpartitioned) is refused; the same cookie with `Partitioned` is kept.
* Every cookie core sets on B for the launch (`MoodleSession`, the OIDC state cookie `lti1p3_state-...`, `cookiecheck`) is `SameSite=None; Secure` and partitioned to the embedding site. Hence initial launch, navigation inside the frame, reload, expiry (detected as a login page), a new launch and logout/relaunch all work in Chromium.
* A browser that gives the frame no storage at all (Safari "block all cross-site cookies", Firefox with its strictest mode) cannot keep that state; this is only EMULATED here (`blockFrameStorage`). Real runs of those browsers: not verified.

Mitigation implemented (the best one a local plugin can offer through supported extension points):

1. Core's own CHIPS handling is relied on, not replaced (CE-G2).
2. Storage Access API recovery: user gesture in the tool frame -> `document.requestStorageAccess()` -> validated `retry` message -> the platform restarts the launch from its own rendered `src`. Bounded to two restarts per page load. No unpartitioned cookie is written by the plugin.
3. Detection and the new-window fallback for everything else.

Boundary, stated plainly: the plugin does not move OIDC state or nonce out of cookies/session (no extension point, CE-G1), does not rewrite session cookies (CE-G2), and cannot make the embedded session independent of third-party cookie policy. Its supported claim is "diagnosis and fallback, plus a permission path where the browser offers one". Releasing "reliably embedded without classic third-party cookies" would need core changes (platform storage for the OIDC state, or per-context cookie attributes) and real-browser evidence for Chrome, Firefox, Safari and iOS (CE-G4).

## 4. Configuration

| Setting | Meaning |
| --- | --- |
| consumerenabled / providerenabled | enable injection per role (default on); both off = inactive |
| peerbridge | platform side: `auto` (default, silence is normal) or `required` (silence = blocked frame; only when every tool runs the bridge with providerltipages on) |
| providerltipages | also inject on auth_lti and enrol_lti launch pages |
| allowedorigins | exact origins, one per line; http only when the site itself runs on http (development) |
| noncelifetime | lifetime of fallback tokens |
| handshaketimeout | seconds before `blocked_frame` |
| fallbackmode | suggest automatically or manual link only |
| storageaccess | tool side: offer the Storage Access API permission button (default on) |

## Upgrade 0.2.0 -> 0.3.0

`db/upgrade.php` step 2026102700: stores the role switches explicitly when they were unset (0.2.0 read unset as on,
so the mode does not change) and adds `peerbridge=auto`. Values an administrator chose are kept. Behaviour change:
a platform whose tool never answers no longer reports `blocked_frame`; set `peerbridge=required` to keep that.

## Installation and rename (0.2.0)

0.2.0 is the first release of `local_ltixsembed` (renamed by owner decision 2026-10-25; the former component name is recorded in
docs/CHANGELOG.md and docs/NAMING.md). There is **no upgrade path** from the 0.1.0 alpha, because Moodle cannot upgrade one
component into another: uninstall the old plugin (`admin/cli/uninstall_plugins.php --plugins=<old component> --run` or the
plugins overview), which deletes its settings (the plugin has no tables), remove its directory, and install this plugin fresh. `db/upgrade.php` contains no
steps; later releases add steps there when they change the schema or stored data of this component.
