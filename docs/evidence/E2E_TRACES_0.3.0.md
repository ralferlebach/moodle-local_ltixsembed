# E2E traces 0.3.0 (CE-02 / CE-03), 2026-10-27/28

Generated from the Playwright traces (`support.recordLaunch`) of the local two-site stack runs; last run per
(pair, browser, test) wins. Columns: requests to B's OIDC login endpoint (core sends two per launch), platform auth
requests on A, tool launch POSTs on B, redirects of frame navigations, OIDC state cookies set by B (WebKit hides
Set-Cookie from Playwright: 0 there), windows opened, runtime state of the platform notice (fallback state), tool
notice state / bridge channel, and the outcome - embedded (`iframe`) and top-level fallback are recorded separately.

Conditions: `default` = browser as shipped; `blocked3pc` = the browser's own third-party cookie switch (Chromium
profile setting - partitioned cookies stay allowed; Firefox `network.cookie.cookieBehavior=1` - all third-party
cookies rejected); `nostorage` = EMULATED frame without any storage (Chromium/Firefox; WebKit blocks third-party cookies
natively, so its `default` is already that case). `-fallback` rows: the learner used the new-window option. `required-` /
`auto-silent`: the tool runs the plugin but its allow-list excludes the platform. `auto-<mode>`: automatic fallback mode.

| Pair | Browser | Condition / mode | OIDC login req. (B) | auth req. (A) | launch POST (B) | frame redirects | state cookies | windows | platform state | tool state / channel | outcome iframe / top-level |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A 4.5 -> B 5.3 | chromium | auto-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | chromium | auto-cooperative | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (auto_opened) | storage_unavailable / open | failed / success |
| A 4.5 -> B 5.3 | chromium | auto-silent | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | embedded / none | success / none |
| A 4.5 -> B 5.3 | chromium | blocked3pc-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| A 4.5 -> B 5.3 | chromium | blocked3pc-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| A 4.5 -> B 5.3 | chromium | blocked3pc-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| A 4.5 -> B 5.3 | chromium | blocked3pc-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| A 4.5 -> B 5.3 | chromium | default-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| A 4.5 -> B 5.3 | chromium | default-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| A 4.5 -> B 5.3 | chromium | default-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| A 4.5 -> B 5.3 | chromium | default-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| A 4.5 -> B 5.3 | chromium | nostorage-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | chromium | nostorage-consumer-fallback | 4 | 1 | 1 | 1 | 1 | 1 | no_peer (opened) | - / - | failed / success |
| A 4.5 -> B 5.3 | chromium | nostorage-cooperative | 2 | 0 | 0 | 1 | 0 | 0 | storage_unavailable (suggested) | storage_unavailable / open | failed / none |
| A 4.5 -> B 5.3 | chromium | nostorage-cooperative-fallback | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (opened) | storage_unavailable / open | failed / success |
| A 4.5 -> B 5.3 | chromium | nostorage-none | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | - / - | failed / none |
| A 4.5 -> B 5.3 | chromium | nostorage-provider | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | storage_unavailable / none | failed / none |
| A 4.5 -> B 5.3 | chromium | required-silent | 2 | 1 | 1 | 3 | 1 | 0 | blocked_frame (suggested) | embedded / none | success / none |
| A 4.5 -> B 5.3 | firefox | auto-consumer | 2 | 0 | 0 | 0 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | firefox | auto-cooperative | 4 | 1 | 1 | 0 | 1 | 1 | storage_unavailable (auto_opened) | storage_unavailable / open | failed / success |
| A 4.5 -> B 5.3 | firefox | auto-silent | 2 | 2 | 1 | 3 | 1 | 0 | no_peer (none) | embedded / none | success / none |
| A 4.5 -> B 5.3 | firefox | blocked3pc-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | firefox | blocked3pc-cooperative | 2 | 0 | 0 | 1 | 0 | 0 | needs_user_intervention (suggested) | needs_user_intervention / open | failed / none |
| A 4.5 -> B 5.3 | firefox | blocked3pc-none | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | - / - | failed / none |
| A 4.5 -> B 5.3 | firefox | blocked3pc-provider | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | needs_user_intervention / none | failed / none |
| A 4.5 -> B 5.3 | firefox | default-consumer | 2 | 2 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| A 4.5 -> B 5.3 | firefox | default-cooperative | 2 | 2 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| A 4.5 -> B 5.3 | firefox | default-none | 2 | 2 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| A 4.5 -> B 5.3 | firefox | default-provider | 2 | 2 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| A 4.5 -> B 5.3 | firefox | nostorage-consumer | 2 | 0 | 0 | 0 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | firefox | nostorage-consumer-fallback | 4 | 1 | 1 | 0 | 1 | 1 | no_peer (opened) | - / - | failed / success |
| A 4.5 -> B 5.3 | firefox | nostorage-cooperative | 2 | 0 | 0 | 0 | 0 | 0 | storage_unavailable (suggested) | storage_unavailable / open | failed / none |
| A 4.5 -> B 5.3 | firefox | nostorage-cooperative-fallback | 4 | 1 | 1 | 0 | 1 | 1 | storage_unavailable (opened) | storage_unavailable / open | failed / success |
| A 4.5 -> B 5.3 | firefox | nostorage-none | 2 | 0 | 0 | 0 | 0 | 0 | - (-) | - / - | failed / none |
| A 4.5 -> B 5.3 | firefox | nostorage-provider | 2 | 0 | 0 | 0 | 0 | 0 | - (-) | storage_unavailable / none | failed / none |
| A 4.5 -> B 5.3 | firefox | required-silent | 2 | 2 | 1 | 3 | 1 | 0 | blocked_frame (suggested) | embedded / none | success / none |
| A 4.5 -> B 5.3 | webkit | auto-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | webkit | auto-cooperative | 4 | 1 | 1 | 1 | 0 | 1 | needs_user_intervention (auto_opened) | needs_user_intervention / open | failed / success |
| A 4.5 -> B 5.3 | webkit | auto-silent | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | needs_user_intervention / none | failed / none |
| A 4.5 -> B 5.3 | webkit | default-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> B 5.3 | webkit | default-cooperative | 2 | 0 | 0 | 1 | 0 | 0 | needs_user_intervention (suggested) | needs_user_intervention / open | failed / none |
| A 4.5 -> B 5.3 | webkit | default-none | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | - / - | failed / none |
| A 4.5 -> B 5.3 | webkit | default-provider | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | needs_user_intervention / none | failed / none |
| A 4.5 -> B 5.3 | webkit | nostorage-consumer-fallback | 4 | 1 | 1 | 1 | 0 | 1 | no_peer (opened) | - / - | failed / success |
| A 4.5 -> B 5.3 | webkit | nostorage-cooperative-fallback | 4 | 1 | 1 | 1 | 0 | 1 | needs_user_intervention (opened) | needs_user_intervention / open | failed / success |
| A 4.5 -> B 5.3 | webkit | required-silent | 2 | 0 | 0 | 1 | 0 | 0 | blocked_frame (suggested) | needs_user_intervention / none | failed / none |
| A 4.5 -> A2 4.5 | chromium | auto-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> A2 4.5 | chromium | auto-cooperative | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (auto_opened) | storage_unavailable / open | failed / success |
| A 4.5 -> A2 4.5 | chromium | auto-silent | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | embedded / none | success / none |
| A 4.5 -> A2 4.5 | chromium | blocked3pc-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| A 4.5 -> A2 4.5 | chromium | blocked3pc-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| A 4.5 -> A2 4.5 | chromium | blocked3pc-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| A 4.5 -> A2 4.5 | chromium | blocked3pc-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| A 4.5 -> A2 4.5 | chromium | default-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| A 4.5 -> A2 4.5 | chromium | default-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| A 4.5 -> A2 4.5 | chromium | default-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| A 4.5 -> A2 4.5 | chromium | default-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| A 4.5 -> A2 4.5 | chromium | nostorage-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| A 4.5 -> A2 4.5 | chromium | nostorage-consumer-fallback | 4 | 1 | 1 | 1 | 1 | 1 | no_peer (opened) | - / - | failed / success |
| A 4.5 -> A2 4.5 | chromium | nostorage-cooperative | 2 | 0 | 0 | 1 | 0 | 0 | storage_unavailable (suggested) | storage_unavailable / open | failed / none |
| A 4.5 -> A2 4.5 | chromium | nostorage-cooperative-fallback | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (opened) | storage_unavailable / open | failed / success |
| A 4.5 -> A2 4.5 | chromium | nostorage-none | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | - / - | failed / none |
| A 4.5 -> A2 4.5 | chromium | nostorage-provider | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | storage_unavailable / none | failed / none |
| A 4.5 -> A2 4.5 | chromium | required-silent | 2 | 1 | 1 | 3 | 1 | 0 | blocked_frame (suggested) | embedded / none | success / none |
| B 5.3 -> B2 5.3 | chromium | auto-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| B 5.3 -> B2 5.3 | chromium | auto-cooperative | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (auto_opened) | storage_unavailable / open | failed / success |
| B 5.3 -> B2 5.3 | chromium | auto-silent | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | embedded / none | success / none |
| B 5.3 -> B2 5.3 | chromium | blocked3pc-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| B 5.3 -> B2 5.3 | chromium | blocked3pc-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| B 5.3 -> B2 5.3 | chromium | blocked3pc-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| B 5.3 -> B2 5.3 | chromium | blocked3pc-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| B 5.3 -> B2 5.3 | chromium | default-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| B 5.3 -> B2 5.3 | chromium | default-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| B 5.3 -> B2 5.3 | chromium | default-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| B 5.3 -> B2 5.3 | chromium | default-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| B 5.3 -> B2 5.3 | chromium | nostorage-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| B 5.3 -> B2 5.3 | chromium | nostorage-consumer-fallback | 4 | 1 | 1 | 1 | 1 | 1 | no_peer (opened) | - / - | failed / success |
| B 5.3 -> B2 5.3 | chromium | nostorage-cooperative | 2 | 0 | 0 | 1 | 0 | 0 | storage_unavailable (suggested) | storage_unavailable / open | failed / none |
| B 5.3 -> B2 5.3 | chromium | nostorage-cooperative-fallback | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (opened) | storage_unavailable / open | failed / success |
| B 5.3 -> B2 5.3 | chromium | nostorage-none | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | - / - | failed / none |
| B 5.3 -> B2 5.3 | chromium | nostorage-provider | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | storage_unavailable / none | failed / none |
| B 5.3 -> B2 5.3 | chromium | required-silent | 2 | 1 | 1 | 3 | 1 | 0 | blocked_frame (suggested) | embedded / none | success / none |
| B 5.3 -> A 4.5 | chromium | auto-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| B 5.3 -> A 4.5 | chromium | auto-cooperative | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (auto_opened) | storage_unavailable / open | failed / success |
| B 5.3 -> A 4.5 | chromium | auto-silent | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | embedded / none | success / none |
| B 5.3 -> A 4.5 | chromium | blocked3pc-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| B 5.3 -> A 4.5 | chromium | blocked3pc-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| B 5.3 -> A 4.5 | chromium | blocked3pc-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| B 5.3 -> A 4.5 | chromium | blocked3pc-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| B 5.3 -> A 4.5 | chromium | default-consumer | 2 | 1 | 1 | 3 | 1 | 0 | no_peer (none) | - / - | success / none |
| B 5.3 -> A 4.5 | chromium | default-cooperative | 2 | 1 | 1 | 3 | 1 | 0 | embedded (none) | embedded / open | success / none |
| B 5.3 -> A 4.5 | chromium | default-none | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | - / - | success / none |
| B 5.3 -> A 4.5 | chromium | default-provider | 2 | 1 | 1 | 3 | 1 | 0 | - (-) | embedded / none | success / none |
| B 5.3 -> A 4.5 | chromium | nostorage-consumer | 2 | 0 | 0 | 1 | 0 | 0 | no_peer (none) | - / - | failed / none |
| B 5.3 -> A 4.5 | chromium | nostorage-consumer-fallback | 4 | 1 | 1 | 1 | 1 | 1 | no_peer (opened) | - / - | failed / success |
| B 5.3 -> A 4.5 | chromium | nostorage-cooperative | 2 | 0 | 0 | 1 | 0 | 0 | storage_unavailable (suggested) | storage_unavailable / open | failed / none |
| B 5.3 -> A 4.5 | chromium | nostorage-cooperative-fallback | 4 | 1 | 1 | 1 | 1 | 1 | storage_unavailable (opened) | storage_unavailable / open | failed / success |
| B 5.3 -> A 4.5 | chromium | nostorage-none | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | - / - | failed / none |
| B 5.3 -> A 4.5 | chromium | nostorage-provider | 2 | 0 | 0 | 1 | 0 | 0 | - (-) | storage_unavailable / none | failed / none |
| B 5.3 -> A 4.5 | chromium | required-silent | 2 | 1 | 1 | 3 | 1 | 0 | blocked_frame (suggested) | embedded / none | success / none |
