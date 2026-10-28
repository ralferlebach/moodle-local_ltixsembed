# Security

| Threat | Control | Test |
| --- | --- | --- |
| Forged postMessage (origin, source, nonce, type, context, expiry) | strict validator, exact allow-list, constant-time nonce compare, silent drop | `tests/js/bridge_core.test.mjs`, Playwright forged-message test |
| Replay | JS replay set with cap; PHP single-use markers in MUC | `bridge_core.test.mjs`, `nonce_test` |
| CSRF on fallback | POST only, sesskey, capability and login check | `fallback_test`, `single-site.spec.js` |
| Open redirect | no redirect parameter; target built from cmid | `fallback_test` |
| SSRF in audit | no URL input; own login page only; cURL security helpers asked before every request (never relaxed); redirects followed only within the own origin, each hop pre-checked, max. 3 | `config_audit_test` (blocklist, redirect resolution, same-origin) |
| Token in URL or log | token only in the POST body; events and debugging carry no token or identifier | `fallback_test`, `hook_callbacks_test` |
| Privilege | `viewdiagnostics` is RISK_CONFIG, manager only | `tests` capability checks |
| Retry message steering the platform | fixed payload (one reason), channel nonce, exact origin, source window, expiry, replay; handler uses only the page's own rendered `src`, max. 2 restarts per load | `bridge_core.test.mjs` (retry cases), `security_static.test.mjs`, `session-lifecycle.spec.js` (forged retry) |
| Message to a foreign embedding origin | `hello` only to allow-listed origins, never `*` | `security_static.test.mjs`, `security-matrix.spec.js` |
| Secrets in URLs | none in the form action, frame URL or requests added by the plugin | `security-matrix.spec.js` |
| Unauthorised postMessage data flow (0.3.0) | the tool sends `hello`/`state`/`retry` only to allow-listed origins, the platform `welcome` only to the validated hello origin; payload limited to a state word and booleans/enums | `postmessage-audit.spec.js` (every DELIVERED message recorded: only A<->B, only fixed keys; third origin embedding B receives nothing), `security_static.test.mjs` |
| Replay / tampering in a real browser (0.3.0) | as above | `postmessage-audit.spec.js`: genuine message replayed (`replay`), tampered nonce (`nonce`), expired (`expired`), wrong context (`context`), over-long lifetime (`lifetime`), second window of the tool origin (`source`), platform window (`origin`) - each dropped with that rule, state unchanged |
| Cooperation causing extra windows/launches (0.3.0) | only the platform opens a window (once per page load, only for a reported problem), tool never navigates/opens; restart needs a user grant, max. 2 | `mode_core.test.mjs`, `security_static.test.mjs` (no `window.open`, no `top/parent.location`, exactly one guarded submit), `modes.spec.js` (counts per mode) |
| Log flooding through the report endpoint (0.3.0) | login + capability, fixed vocabulary, 32-hex id, dedup, 20 per session per 10 min; no unauthenticated reports | `report_state_test` |
| Weakened protection | plugin never sets SameSite=None, never edits session cookies, never relaxes settings | `test_g3_plugin_adds_no_headers` |

Wildcards, paths, user info and (on https sites) http origins are rejected by the origin parser. Reporting: via the repository issue tracker, marked security.


Browser and security matrix (CE-02): Chromium is executed locally (UI protocol: `two-site-bridge`, `session-lifecycle`, `accessibility`; security protocol: `security-matrix`, forged-message cases of `two-site-bridge`). Firefox, WebKit and mobile Safari projects exist (`LXS_ALL_BROWSERS=1`) but those browsers are not installed here: **not verified**. Chromium results are no evidence for them.
