---
paths:
  - 'tests/**'
---

# Tests

## Run Browser and Feature tests sequentially
Do not run Pest Browser and Feature suites concurrently. Browser tests use DatabaseMigrations against the shared testing database and can drop tables while Feature tests are still executing, causing unrelated missing-table failures.

## Keep tests independent from Vite hot state
Tests must disable Inertia SSR and use the built Vite manifest instead of public/hot. The hot file can outlive Vite or point to a host unreachable from Sail and browser subprocesses, adding roughly 10-12 seconds per request. Browser tests that spawn PHP servers must pass APP_ENV=testing and INERTIA_SSR_ENABLED=false.
