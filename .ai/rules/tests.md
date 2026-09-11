---
paths:
  - 'tests/**'
---

# Tests

## Run Browser and Feature tests sequentially
Do not run Pest Browser and Feature suites concurrently. Browser tests use DatabaseMigrations against the shared testing database and can drop tables while Feature tests are still executing, causing unrelated missing-table failures.
