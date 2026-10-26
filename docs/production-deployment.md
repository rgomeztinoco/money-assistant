# Production deployment

Use this runbook after a feature has merged into `main` and its required GitHub checks have passed. It updates the existing Lean v1 installation; it does not bootstrap a new host.

Production code lives in `/opt/money-assistant`. Configuration and secrets remain in `/etc/money-assistant` and must never be copied into the repository or release archive.

Deployment support lives in `production/`:

| Command | Purpose |
| --- | --- |
| `release-production` | Verify main and CI, back up, promote, deploy, and verify the release. |
| `deploy-production` | Build, migrate, and replace the application containers. |
| `install-production-services` | Install the application, private-access, and backup systemd units and backup commands. |
| `export-production-backup` | Stream the database into an encrypted backup. |
| `restore-production-backup` | Restore a backup into a separate verification database. |
| `verify-private-ingress` | Check the named Tailscale Service, Funnel state, firewall rules, exposed ports, and canonical HTTPS health. |
| `docker-entrypoint.production` | Load mounted secrets when an application container starts. |

Host security updates use Ubuntu's `unattended-upgrades`; this repository no longer maintains a separate security policy checker or vulnerability ledger. Existing host update settings remain installed.

## Release

After the change has merged, release it from the development checkout with one command:

```bash
production/release-production
```

The command requires a clean `main` checkout and an authenticated GitHub CLI (`gh`) with access to the repository and its Actions runs. It fetches `origin` and requires local `main` to match `origin/main` exactly. Ahead, behind, or diverged checkouts stop with instructions; the command does not update your branch.

It checks the latest `tests.yml` push run on `main` for that exact revision. Both `ci` and `production-stack` must succeed. Running checks and jobs waiting for prerequisites are polled every ten seconds. Failed, cancelled, or skipped checks block the release, as do checks missing from a completed workflow. GitHub authentication or API errors also stop it. After CI passes, the command fetches again and stops if main changed while waiting.

Only then does it create a fresh encrypted backup, promote Git-tracked files into `/opt/money-assistant`, reinstall the systemd units, deploy the production containers, and verify private ingress. It prints the deployed revision when every step succeeds. No separate local test command is required.

`rsync --delete` remains scoped to `/opt/money-assistant/`. Production state is stored in Docker volumes. Host-managed configuration and secrets remain under `/etc/money-assistant`.

Do not run the development Sail deployment commands against the production Compose file. Production uses the fixed `money-assistant-production` Compose project, `/etc/money-assistant/production.env`, dedicated networks and volumes, and loopback port 8443.

## Manual verification and recovery

The release command performs the private-ingress check. Use the lower-level commands below when investigating a failed release.

For optional local diagnostics, `vendor/bin/sail composer test:deployment` builds the frontend and runs the feature and browser suites sequentially. This is not a release prerequisite.

Never run `tailscale serve reset` on this host. Money Assistant must not remove unrelated Tailscale Serve routes.

Require every production container to be running and healthy:

```bash
sudo docker compose \
    --project-name money-assistant-production \
    --env-file /etc/money-assistant/production.env \
    --file /opt/money-assistant/compose.production.yaml \
    ps
```

Verify private HTTPS ingress and the systemd lifecycle:

```bash
sudo /opt/money-assistant/production/verify-private-ingress /etc/money-assistant/production.env
systemctl is-active money-assistant-production.service money-assistant-tailnet.service money-assistant-backup.timer
systemctl is-enabled money-assistant-production.service money-assistant-tailnet.service money-assistant-backup.timer
```

Finally, open the configured `APP_URL` tailnet origin and exercise the feature that triggered the deployment. Check recent logs when verification fails:

```bash
sudo docker compose \
    --project-name money-assistant-production \
    --env-file /etc/money-assistant/production.env \
    --file /opt/money-assistant/compose.production.yaml \
    logs --since 10m web worker scheduler proxy postgres
```

## Failure handling

`deploy-production` stops before replacing application containers when the build, database health check, or migration fails. Inspect the command output and container logs, correct the release, and deploy a new commit.

Migrations may have completed before a later container health check fails. There is no transactional release rollback; do not run migration rollback commands against production. Prefer a forward fix that remains compatible with the deployed schema.

## Restore drill

Periodically select an encrypted backup and restore it into an isolated verification database. The Age identity must be supplied from its private, non-repository location:

```bash
sudo BACKUP_AGE_IDENTITY_FILE=/path/to/private-age-identity \
    /usr/local/sbin/restore-production-backup \
    /var/backups/money-assistant/money-assistant-YYYYMMDDTHHMMSSZ.dump.age \
    money_assistant_restore_YYYYMMDD
```

After independently verifying the restored database, remove only that named verification database:

```bash
sudo docker compose \
    --project-name money-assistant-production \
    --env-file /etc/money-assistant/production.env \
    --file /opt/money-assistant/compose.production.yaml \
    exec --no-TTY postgres \
    dropdb --username money_assistant money_assistant_restore_YYYYMMDD
```

Never pass `money_assistant` as the restore target or drop target.
