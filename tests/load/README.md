# Load tests: out of scope

local_ltixsembed has no hot server-side path that would justify a load test: the pages are
low-traffic (administrator diagnostics, the audit page and the one-time-token fallback POST). The
template's JMeter and k6 harnesses were removed rather than left as broken stubs. If a load
requirement appears later, start from the single-site Playwright seed in `tests/playwright/seed.php`.
