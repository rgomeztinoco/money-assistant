## Agent skills

### Issue tracker

Issues are tracked in this repository's GitHub Issues using the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Triage uses the five default canonical label names. See `docs/agents/triage-labels.md`.

### Domain docs

This repository uses a single-context domain documentation layout. See `docs/agents/domain.md`.

### Development processes

Start the queue worker, logs, and Vite with `vendor/bin/sail artisan dev`. Sail serves HTTP. Run the optional scheduler separately with `vendor/bin/sail artisan schedule:work --no-interaction`.

### Private preview

When the owner says "show me" or asks to preview the application, follow `docs/agents/private-sail-preview.md`. The runbook owns the Sail preparation, tailnet route, T3 preview fallback, and cleanup sequence.
