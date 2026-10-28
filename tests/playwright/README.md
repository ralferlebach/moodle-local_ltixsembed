# local_ltixsembed - Playwright browser tests

Eight specs, all written against live sites (they cannot run in the static moodle-plugin-ci job).

| Spec | Needs | Covers |
| --- | --- | --- |
| `single-site.spec.js` | one Moodle site with the plugin (`seed.php` creates the fixture) | settings, capability-test page, audit page, fallback endpoint refusals |
| `session-lifecycle.spec.js` | the two-site stack | CE-01: cookie matrix and session lifecycle under REAL Chromium third-party cookie blocking; Storage Access API path (emulated) |
| `security-matrix.spec.js` | the two-site stack | CE-02: foreign embedding origin hears nothing, no secrets in URLs, no plugin framing headers |
| `accessibility.spec.js` | the two-site stack | structural a11y: live regions, keyboard-only fallback, named controls |
| `modes.spec.js` | the two-site stack | CE-02: consumer-only, provider-only, cooperative (and none) under three browser conditions, with traces and the "never worse together" bound |
| `browser-conditions.spec.js` | the two-site stack | CE-03: cookies per mode under real blocking, real Storage Access API in Chromium, CSP frame-ancestors, X-Frame-Options, session expiry and relaunch |
| `postmessage-audit.spec.js` | the two-site stack | CE-04: every delivered postMessage audited, forged/replayed messages dropped by rule, third origin |
| `two-site-bridge.spec.js` | the two-site stack, seed file `LTI_SEED` (default `/home/claude/e2e/state/seed.json`) | blocked storage detected, new-window fallback reaches the course on B, forged postMessage ignored, reload/back, embedded state without blocking |

## Run

```bash
cd tests/playwright
npm install && npx playwright install --with-deps chromium
# single site (CI):
eval "$(php seed.php)" && LXS_BASE_URL=http://localhost:8000 npx playwright test single-site.spec.js
# two-site stack (preferred: the stack's wrapper)
/home/claude/e2e/bin/e2e-test -c "$PWD/playwright.config.js"
# or directly:
NO_PROXY=.platform-a.test,.tool-b.test,localhost,127.0.0.1 NODE_PATH=/home/claude/e2e/node_modules \
  npx playwright test -c playwright.config.js two-site-bridge.spec.js
```

`npm install` also installs `axe-core` (accessibility spec; `LXS_AXE_PATH` may point to another copy). `LXS_EVIDENCE_DIR`
sets where traces go. `LXS_ALL_BROWSERS=1` adds the firefox, webkit and mobile-safari projects - only for browsers that
are actually installed. They are NOT installed on the
development machine: their results are "not verified locally".

## Honest limits

The blocked cases use real Chromium third-party cookie blocking (profile setting, helper `newBlockingContext`
of the stack) when `/home/claude/e2e/lib/e2e.js` exists; otherwise `blockFrameStorage` emulates it
(route-based plus an init script). Only Chromium runs here. See docs/ACCEPTANCE.md.
