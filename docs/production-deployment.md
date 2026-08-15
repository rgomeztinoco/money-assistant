# Production deployment

Use this runbook after a feature has merged into `main` and its required GitHub checks have passed. It updates the existing Lean v1 installation; it does not bootstrap a new host.

Production code lives in `/opt/money-assistant`. Configuration and secrets remain in `/etc/money-assistant` and must never be copied into the repository or release archive.

## 1. Prepare the release

From the development checkout, confirm there are no local changes, update `main`, and run the test suite:

```bash
git status --short --branch
git fetch origin
git switch main
git pull --ff-only origin main
vendor/bin/sail artisan test --compact
```

Stop if the worktree is not clean, `main` cannot fast-forward, the commit has not passed its required GitHub checks, or a test fails.

Record the revision being deployed:

```bash
git rev-parse HEAD
```

## 2. Create the pre-deployment backup

Run the installed backup unit and require it to succeed before changing production code:

```bash
sudo systemctl start money-assistant-backup.service
sudo systemctl --no-pager --full status money-assistant-backup.service
sudo find /var/backups/money-assistant -maxdepth 1 -type f -name 'money-assistant-*.dump.age' -printf '%TY-%Tm-%TdT%TH:%TM:%TS %f\n' | sort | tail -n 1
```

The service must report `status=0/SUCCESS`, and the final command must show a new encrypted backup. Do not deploy when the backup fails.

## 3. Promote the tracked snapshot

Build a temporary archive from Git-tracked files only, then synchronize that exact snapshot into `/opt/money-assistant`:

```bash
release_directory="$(mktemp -d /tmp/money-assistant-release.XXXXXX)"
trap 'rm -rf -- "$release_directory"' EXIT
chmod 0755 "$release_directory"

git archive --format=tar HEAD | tar -xf - -C "$release_directory"
sudo install -d -o root -g root -m 0755 /opt/money-assistant
sudo rsync --archive --delete --chown=root:root "$release_directory"/ /opt/money-assistant/
sudo chmod 0755 /opt/money-assistant
```

`rsync --delete` is intentionally scoped to the fixed `/opt/money-assistant/` code directory. Production state is stored in Docker volumes, while host-managed configuration and secrets are under `/etc/money-assistant`.

If a release changes the systemd units or backup commands, reinstall their retained definitions after promoting the snapshot:

```bash
sudo /opt/money-assistant/install-production-services
sudo /opt/money-assistant/install-production-backup
```

Run `sudo /opt/money-assistant/install-production-security-updates` only when the release changes the retained security-update policy or installer.

## 4. Deploy

Run the production deployment command from the installed snapshot:

```bash
sudo /opt/money-assistant/deploy-production
```

The command validates Compose configuration, builds the application image, waits for PostgreSQL, runs migrations with `--force --isolated`, and replaces the web, worker, scheduler, and proxy containers only after migrations succeed. The recreated worker and scheduler containers load the new code automatically.

Do not run the development Sail deployment commands against the production Compose file. Production uses the fixed `money-assistant-production` Compose project, `/etc/money-assistant/production.env`, dedicated networks and volumes, and loopback port 8443.

## 5. Verify

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
sudo /opt/money-assistant/verify-private-ingress /etc/money-assistant/production.env
systemctl is-active money-assistant-production.service money-assistant-tailnet.service money-assistant-backup.timer
systemctl is-enabled money-assistant-production.service money-assistant-tailnet.service money-assistant-backup.timer
```

Finally, open the configured `https://<PRIVATE_HOSTNAME>:8443` tailnet origin and exercise the feature that triggered the deployment. Check recent logs when verification fails:

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
