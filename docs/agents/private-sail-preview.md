# Private Sail preview

Use this runbook when the owner says "show me" or asks to preview the application. It prepares the existing primary Sail checkout on port 8080, tries the T3 collaborative preview, and leaves a private tailnet URL available for review.

The review origin is `https://ricardo-server.tailafbf3d.ts.net:8080`. Tailscale Serve makes it available to devices on the owner's tailnet. The tailnet remains the access-control boundary; this workflow does not publish the application to the internet.

## Network boundaries

Keep the development Compose publications on host loopback. The application, Vite, PostgreSQL, and Mailpit ports in `compose.yaml` must remain bound to `127.0.0.1`.

The Laravel process may listen on `0.0.0.0` inside its container. Docker needs that container-side listener to forward traffic. It is separate from the host-side `127.0.0.1:8080` publication that prevents direct LAN and public access.

Use the fixed port and the existing Sail stack. Do not expose Vite or depend on HMR for remote review. The scheduler remains opt-in and is not part of this flow.

## Prepare the application

Set these values in the uncommitted `.env`. Leave `.env.example` portable.

```dotenv
APP_URL=https://ricardo-server.tailafbf3d.ts.net:8080
APP_PORT=8080
```

Start or reconcile the existing stack, build the current frontend, remove stale Vite hot state, and clear cached configuration:

```bash
vendor/bin/sail up -d
vendor/bin/sail pnpm run build
rm -f public/hot
vendor/bin/sail artisan config:clear
```

Verify the application before changing Tailscale configuration:

```bash
curl --fail --silent --show-error http://127.0.0.1:8080/up > /dev/null
vendor/bin/sail ps
vendor/bin/sail config --format json | jq -e '
    [.services[].ports[]? | .host_ip] | all(. == "127.0.0.1")
'
tailscale status --json | jq -r '.TailscaleIPs[]'
ss -ltn | rg ':(8080|5173|5433|1025|8025)\b'
```

Every Compose publication must pass the loopback check. The listener list may also contain the node's reported Tailscale IPv4 and IPv6 addresses on port 8080 when this runbook has been used before. Those addresses belong to the intended Serve mapping, not Docker. Stop if a development port uses `0.0.0.0`, `[::]`, a LAN address, a public address, or a Tailscale address on any port other than 8080. Fix the host publication rather than changing the container-side HTTP listener.

## Publish to the tailnet

Create or replace only the HTTPS port-8080 mapping. Repeating this command is safe:

```bash
tailscale serve --bg --yes --https=8080 http://127.0.0.1:8080
```

Confirm that the mapping has the exact backend, Serve reports it as `tailnet only`, and Funnel has no enabled route:

```bash
tailscale serve status

tailscale serve status --json | jq -e '
    .TCP["8080"].HTTPS == true and
    .Web["ricardo-server.tailafbf3d.ts.net:8080"].Handlers["/"].Proxy == "http://127.0.0.1:8080"
'

tailscale funnel status --json | jq -e '
    [(.AllowFunnel // {})[]] | all(. == false)
'
```

Verify the tailnet origin and compiled assets. Neither check should print a Vite development URL:

```bash
curl --fail --silent --show-error \
    https://ricardo-server.tailafbf3d.ts.net:8080/login | \
    rg '/build/assets/'

! curl --fail --silent --show-error \
    https://ricardo-server.tailafbf3d.ts.net:8080/login | \
    rg 'localhost:5173|@vite/client'
```

If any check fails, report the failed layer. Do not compensate by changing Docker bindings, exposing Vite, enabling Funnel, or creating another tunnel.

## Open the T3 preview

After the application and tailnet URL pass their checks:

1. Call `preview_status` to check T3 collaborative preview status.
2. If no tab exists, call `preview_open` once to initialize it.
3. When an automation host is attached, use `preview_navigate` to open `https://ricardo-server.tailafbf3d.ts.net:8080`, then inspect the rendered page.
4. If the automation host is still unavailable after the `preview_open` retry, stop preview troubleshooting and give the owner the verified tailnet URL.

A missing T3 automation host does not indicate a Sail or Tailscale failure. If the owner wants the inline preview restored, ask them to focus or reopen T3 Code first, then fully relaunch the desktop application if the host remains absent.

Leave Sail and the port-8080 Serve mapping active after reporting the preview ready. This lets the owner keep reviewing after the agent turn ends.

## Cleanup

Run cleanup only when the owner explicitly asks to stop or clean up the development preview. Remove the exact Serve mapping before stopping Sail:

```bash
tailscale serve --https=8080 off
tailscale serve status
vendor/bin/sail stop
```

Confirm that the port-8080 entry is absent and unrelated routes are still present. Never use `tailscale serve reset`; it removes production and other services from the host as well.
