# Manual production deployment

Money Assistant publishes immutable release artifacts from a green push to `main`, but it never deploys them automatically. Production activation is an explicit command run by an operator on the server.

This process does not create a GitHub deployment environment, GitHub deployment secrets, a deployment SSH account, Tailscale tags, or Tailscale grants. It does not replace the tailnet policy. The production service manages only the Money Assistant Serve route on HTTPS port `8443`; it does not modify unrelated routes such as `3773`.

## One-time server bootstrap

Wait for the selected commit on `main` to publish a GitHub release containing:

- `operational-bundle-<revision>.tar`
- `operational-bundle-<revision>.tar.sha256`
- `release-metadata-<revision>.env`

On the production server, check out that revision and prepare the host-owned configuration:

```bash
sudo install -d -m 0700 /etc/money-assistant/secrets
sudo install -m 0600 .env.production.example /etc/money-assistant/production.env
sudoedit /etc/money-assistant/production.env
```

Replace every example value in `production.env`. Keep `APP_IMAGE_REPOSITORY` set to `ghcr.io/rgomeztinoco/money-assistant`; the deployment command replaces its digest with the selected immutable release.

Create these root-owned `0600` secret files using a protected editor or protected source file. Do not put their values in shell arguments or the repository:

```text
/etc/money-assistant/secrets/application_key
/etc/money-assistant/secrets/application_previous_keys
/etc/money-assistant/secrets/database_password
/etc/money-assistant/secrets/google_gmail_client_secret
```

`application_key` must initially contain the current application's `APP_KEY` so retained encrypted credentials remain decryptable. `application_previous_keys` may be an empty file when there are no previous keys.

Install the stable local deployment command:

```bash
sudo ./install-production-deployment rgomeztinoco/money-assistant
```

The installer only writes Money Assistant files under `/etc/money-assistant`, `/var/lib/money-assistant`, `/usr/local/sbin`, and the two Money Assistant systemd unit files. It reloads systemd but does not start production or change networking.

## Deploy a release

Copy the full 40-character revision from the successful GitHub release, then run on the server:

```bash
sudo deploy-production-release <revision>
```

The command downloads the three public release assets, checks the revision, repository, image digest, and bundle checksum, then invokes the transactional production launcher. A failed migration or candidate health check restores the previous application, operational bundle, and database snapshot before writers resume.

After a successful deployment, it enables production after reboot and verifies the active release, HTTPS `8443`, the loopback-only database listener, and disabled Funnel state.

Inspect the result with:

```bash
sudo systemctl status money-assistant-production.service money-assistant-tailnet.service
sudo verify-production-deployment
tailscale serve status
```

## Routine feature deployment

1. Merge a pull request only after the required `ci` and `production-stack` checks pass.
2. Wait for the corresponding `release-<revision>` GitHub release to finish publishing.
3. Open a server console and run `sudo deploy-production-release <revision>`.
4. Confirm that `sudo verify-production-deployment` passes.

There is no GitHub-to-server credential and no automatic deployment. Merging publishes a deployable release; it does not change production.

## Current-data promotion

The first release activation and promotion of the existing development data are separate operations. A first deployment creates an isolated production database volume and does not modify the currently running development database.

Do not delete or sanitize the current development database until the controlled cutover in GitHub issue `#77` has captured and verified its final backup, stopped all old writers, restored the data into the production volume, verified the financial-state fingerprint and credentials, and passed the live Gmail, queue, scheduler, monitoring, and reboot checks.
