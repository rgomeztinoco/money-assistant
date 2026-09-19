# Production deployment

Use this runbook after a feature has merged into `main` and its required GitHub checks have passed. It updates the existing Lean v1 installation; it does not bootstrap a new host.

Production code lives in `/opt/money-assistant`. Configuration and secrets remain in `/etc/money-assistant` and must never be copied into the repository or release archive.

Deployment support lives in `production/`:

| Command | Purpose |
| --- | --- |
| `release-production` | Back up, promote, deploy, and verify the current `main` revision. |
| `deploy-production` | Build, migrate, and replace the application containers. |
| `install-production-services` | Install the application, private-access, and backup systemd units and backup commands. |
| `export-production-backup` | Stream the database into an encrypted backup. |
| `restore-production-backup` | Restore a backup into a separate verification database. |
| `verify-private-ingress` | Check the named Tailscale Service, Funnel state, firewall rules, exposed ports, and canonical HTTPS health. |
| `docker-entrypoint.production` | Load mounted secrets when an application container starts. |

Host security updates use Ubuntu's `unattended-upgrades`; this repository no longer maintains a separate security policy checker or vulnerability ledger. Existing host update settings remain installed.

## Release

After the change has merged and passed its required GitHub checks, run the test suite from the development checkout:

```bash
vendor/bin/sail composer test:deployment
```

This builds the current frontend, runs the feature suite, then runs the browser suite with two workers. The suites stay separate because they use different database reset strategies.

Then release it with one command:

```bash
production/release-production
```

The command requires a clean `main` checkout, fetches and fast-forwards from `origin/main`, and records the exact revision. It creates a fresh encrypted backup before copying any files, promotes only Git-tracked files into `/opt/money-assistant`, reinstalls the systemd units, deploys the production containers, and verifies private ingress. It prints the deployed revision when every step succeeds.

`rsync --delete` remains scoped to `/opt/money-assistant/`. Production state is stored in Docker volumes. Host-managed configuration and secrets remain under `/etc/money-assistant`.

Do not run the development Sail deployment commands against the production Compose file. Production uses the fixed `money-assistant-production` Compose project, `/etc/money-assistant/production.env`, dedicated networks and volumes, and loopback port 8443.

## Canonical tailnet hostname cutover

This is a one-time change from `https://ricardo-server.tailafbf3d.ts.net:8443` to `https://money-assistant.tailafbf3d.ts.net`. Keep the old node-level route and the old Google OAuth redirect registered until every verification step passes. Never use `tailscale serve reset`; the host has unrelated Serve routes.

### 1. Capture the working state

Run these commands on `ricardo-server` and keep the output outside the repository:

```bash
sudo tailscale status --json > /root/money-assistant-tailscale-status.before.json
sudo tailscale serve status --json > /root/money-assistant-serve-status.before.json
sudo tailscale funnel status --json > /root/money-assistant-funnel-status.before.json
sudo install -m 0600 /etc/money-assistant/production.env /root/money-assistant-production.env.before-service
curl --fail --silent --show-error https://ricardo-server.tailafbf3d.ts.net:8443/up
```

Confirm that the saved Serve configuration includes OpenClaw's node-level HTTPS port 443 route and Money Assistant's node-level port 8443 route. Stop if the old Money Assistant health check fails.

### 2. Stage the tailnet policy

Open the Tailscale admin console policy editor. Merge these entries into the existing policy rather than replacing existing owners, grants, auto-approvers, or tests:

```json
{
  "tagOwners": {
    "tag:server": ["autogroup:admin"]
  },
  "autoApprovers": {
    "services": {
      "svc:money-assistant": ["tag:server"]
    }
  }
}
```

Add grants that preserve every connection the host needs after it loses its user identity. In particular, cover owner administration of `tag:server`, the host's existing private services and outbound dependencies, and the owner's devices reaching `svc:money-assistant` on `tcp:443`. Add policy tests for the required allowed connections and for an untrusted source being denied. Validate and save the policy before tagging the host.

Applying a tag removes the host's user identity. Do not continue if any access currently inherited from the owner account lacks an equivalent `tag:server` grant.

In the Tailscale Services page, define `svc:money-assistant` with endpoint `tcp:443`. Do not alter the node-level port 443 route.

### 3. Stage both OAuth callbacks

In Google Cloud Console, open the production OAuth client and add this authorized redirect URI:

```text
https://money-assistant.tailafbf3d.ts.net/settings/connections/gmail/callback
```

Keep the old `https://ricardo-server.tailafbf3d.ts.net:8443/settings/connections/gmail/callback` entry until Gmail authorization succeeds on the new hostname.

### 4. Tag the host and advertise the Service

In the Tailscale Devices page, assign `tag:server` to `ricardo-server`. Immediately verify that owner administration, the node hostname, OpenClaw, and other private development routes still work. Stop and restore the policy before doing any application work if access changed unexpectedly.

On the server, configure only the named Service endpoint:

```bash
sudo tailscale serve --service=svc:money-assistant --https=443 http://127.0.0.1:8443
sudo tailscale serve status --json
```

If the policy does not auto-approve the advertisement, approve the pending `ricardo-server` host on the Service page. From another tailnet device, prove the new path reaches the existing backend:

```bash
curl --fail --silent --show-error https://money-assistant.tailafbf3d.ts.net/up
```

The old `:8443` URL must still work at this point.

### 5. Deploy the canonical origin

Set these exact public values in `/etc/money-assistant/production.env`. Leave the secret-file settings unchanged:

```dotenv
PRIVATE_HOSTNAME=money-assistant.tailafbf3d.ts.net
APP_URL=https://money-assistant.tailafbf3d.ts.net
GOOGLE_GMAIL_REDIRECT_URI=https://money-assistant.tailafbf3d.ts.net/settings/connections/gmail/callback
```

After this change has merged and passed its required checks, run `production/release-production`. The release installs the service-scoped systemd lifecycle, deploys the new origin, and runs `verify-private-ingress`. A successful verifier proves all of the following:

- `svc:money-assistant` serves HTTPS port 443 and proxies only to `http://127.0.0.1:8443`.
- Funnel is disabled, UFW remains default-deny, and Docker still publishes only `127.0.0.1:8443`.
- No application listener is exposed on an ordinary LAN or public interface.
- `https://money-assistant.tailafbf3d.ts.net/up` responds successfully.

### 6. Verify the owner workflow and reboot lifecycle

Use another tailnet device for the owner-facing checks. Sign in again because sessions are scoped to the old hostname. Verify login, generated redirects, the health endpoint, Gmail connection or reconnection, and a Gmail synchronization. Confirm that OpenClaw's node-level port 443 route still works and that `tailscale funnel status --json` contains no enabled entry.

Reboot `ricardo-server`, then run:

```bash
systemctl is-active money-assistant-production.service money-assistant-tailnet.service money-assistant-backup.timer
systemctl is-enabled money-assistant-production.service money-assistant-tailnet.service money-assistant-backup.timer
sudo /opt/money-assistant/production/verify-private-ingress /etc/money-assistant/production.env
sudo tailscale serve status --json
```

Repeat the canonical health, login, Gmail, and OpenClaw checks. This proves systemd restored the named advertisement without changing unrelated Serve routes.

### 7. Retire the old route

Only after every check above passes, remove the old Money Assistant node-level route with its exact port-scoped command:

```bash
sudo tailscale serve --https=8443 off
sudo /opt/money-assistant/production/verify-private-ingress /etc/money-assistant/production.env
sudo tailscale serve status --json
```

Confirm the old `:8443` URL no longer responds, the canonical URL still works, and OpenClaw's port 443 route remains present. The cutover is then complete. Remove the old Google OAuth redirect only after the new Gmail callback has succeeded.

### Roll back the cutover

While the old route is retained, rollback does not require a Tailscale reset. Restore these values in `/etc/money-assistant/production.env`:

```dotenv
APP_URL=https://ricardo-server.tailafbf3d.ts.net:8443
GOOGLE_GMAIL_REDIRECT_URI=https://ricardo-server.tailafbf3d.ts.net:8443/settings/connections/gmail/callback
```

Keep `PRIVATE_HOSTNAME=money-assistant.tailafbf3d.ts.net` so the named-service verifier still describes the intended service. Redeploy the containers with `sudo /opt/money-assistant/production/deploy-production`, then verify the old URL, login, redirects, and Gmail authorization directly. If the named Service itself is causing trouble, stop only its lifecycle with `sudo systemctl stop money-assistant-tailnet.service`; its service-scoped `ExecStop` leaves node-level routes alone.

If rollback is required after the old route was removed, restore it explicitly:

```bash
sudo tailscale serve --bg --https=8443 http://127.0.0.1:8443
curl --fail --silent --show-error https://ricardo-server.tailafbf3d.ts.net:8443/up
```

Restore the saved environment file if needed, but review its values before replacing the live file. Do not restore the complete saved Serve JSON and do not run a global reset because either action can overwrite unrelated routes.

## Manual verification and recovery

The release command performs the private-ingress check. Use the lower-level commands below when investigating a failed release.

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
