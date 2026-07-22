# OpenClaw integration surfaces and constraints

Research date: 2026-07-22

## Question

Which officially supported OpenClaw integration mechanisms can Money Assistant rely on for authenticated tool calls, scheduled outbound communication, event delivery, and conversational responses, and what must the Laravel-facing contract accommodate?

## Decision

Use a small, native OpenClaw **tool plugin** for calls from the agent to Money Assistant, OpenClaw's authenticated **hook ingress** for events from Money Assistant to OpenClaw, and an explicitly bound and allowlisted OpenClaw **messaging channel** for the user conversation. Money Assistant remains the system of record and owns reminder timing; OpenClaw interprets user requests, calls the app's capabilities, and delivers the resulting conversation.

Do not make the generic Gateway HTTP APIs (`/tools/invoke`, `/v1/responses`, or WebSocket control RPC) the normal application boundary. Their shared-secret authentication is an owner/operator boundary, not a narrow service scope. Also do not give OpenClaw database access or implement financial logic in a plugin, skill, cron prompt, or conversation memory.

## Local installation finding

The workspace host available for this research does not expose the user's server installation. A read-only inspection found no `openclaw` executable, OpenClaw state/config directory, running service or process, or OpenClaw container. The only running containers are the Money Assistant development services. Therefore the installed version and enabled channel/plugin configuration could not be verified without access to the actual local server.

The official latest release at research time is [`v2026.7.1`](https://github.com/openclaw/openclaw/releases/tag/v2026.7.1). Treat the design below as the supported target and add a deployment gate on the real server: record `openclaw --version`, inspect the plugin runtime, probe Gateway/channel health, and confirm hook behavior before implementation is accepted. Pin the integration plugin's OpenClaw peer/API compatibility to the verified server version rather than silently tracking `latest`.

## Supported surfaces

### Agent to Money Assistant: native tool plugin

OpenClaw's Plugin SDK officially supports agent tools registered with `api.registerTool(...)`. A tool declares JSON input parameters, may declare a structured output schema, must be declared in `openclaw.plugin.json`, and can be optional so it is exposed only after an explicit `tools.allow` opt-in. OpenClaw recommends optional tools for side effects and supports a permission request before execution. Tool factories receive conversation context such as `deliveryContext` and `requesterSenderId`. See [Building plugins: registering tools](https://docs.openclaw.ai/plugins/building-plugins#registering-tools).

Build one local tool-only plugin that calls a private, authenticated Laravel API. Expose task-shaped capabilities rather than a generic HTTP proxy, SQL access, or controller dispatcher. The initial capability families should be:

- read spending summaries, comparisons, categories, and review-queue items;
- approve or correct proposed descriptions/categories;
- record an explicitly requested manual transaction or refund;
- retrieve the status/result of a previous mutation.

Separate read tools from mutation tools in the manifest and tool policy. Mark every mutation optional and require explicit opt-in; use OpenClaw permission requests where an action needs another confirmation after model selection. The plugin should translate the stable Laravel JSON contract into concise structured tool results, but it must not calculate financial answers itself.

### Money Assistant to OpenClaw: authenticated hook ingress

OpenClaw can expose `POST /hooks/wake` for a main-session system event and `POST /hooks/agent` for an isolated agent turn. Agent hooks accept an `idempotencyKey` and optional delivery routing (`deliver`, `channel`, `to`). Custom mapped hook names can transform a small application event into a fixed `wake` or `agent` action. Hook authentication is header-only with a dedicated token; query-string credentials are rejected. OpenClaw says to keep the endpoint behind loopback, a tailnet, or a trusted reverse proxy, restrict `allowedAgentIds`, and leave caller-selected session keys disabled unless constrained. See [Scheduled tasks: webhooks](https://docs.openclaw.ai/automation/cron-jobs#webhooks).

Use a mapped route such as `/hooks/money-assistant`, fixed to the Money Assistant agent and delivery destination. Laravel should send only an event envelope such as:

```json
{
  "event_id": "01J...",
  "event_type": "review.digest.ready",
  "occurred_at": "2026-07-22T21:00:00Z"
}
```

The mapped prompt tells the agent to fetch the current material through its read tool and then communicate it. This keeps transaction details out of an untrusted prompt payload, prevents the caller from selecting an agent/session/model/recipient, and ensures stale events resolve against current application state. Use `event_id` as the hook `idempotencyKey`, persist delivery attempts in Laravel, and make retries safe. A successful hook submission means OpenClaw accepted work; it must not be treated as proof that the user saw or answered the message.

Prefer an isolated agent hook for a daily review digest because it produces a self-contained unattended turn and can announce its final response to an explicit chat target. Use `/hooks/wake` only for events that intentionally belong in the main session/heartbeat flow.

### Scheduling and outbound delivery

OpenClaw cron is an officially supported persistent scheduler. It supports one-shot, interval, and cron schedules, model-backed isolated turns, and `announce`, `webhook`, or no fallback delivery. The Gateway must be running for jobs to fire; proactive DM delivery needs an explicit target or a configured channel allowlist. Cron job mutation is an operator-admin surface. See [Scheduled tasks](https://docs.openclaw.ai/automation/cron-jobs) and its [delivery rules](https://docs.openclaw.ai/automation/cron-jobs#delivery-and-output).

Money Assistant should nevertheless own the business schedule. Its Laravel scheduler decides when a review is due from financial state and preferences, records the notification intent, and emits the hook event. This preserves the agreed ownership boundary and makes reminders auditable next to the review state. OpenClaw cron may be used only for OpenClaw-local operational reminders or as a deliberately configured fallback, not as the canonical store of Money Assistant reminder rules.

Do not let Laravel create or mutate OpenClaw cron jobs through `/tools/invoke`: cron is denied by default on that endpoint and is operator-only. The generic endpoint is intentionally a broad control boundary, not a least-privilege application API. See [Tools invoke API: security and policy](https://docs.openclaw.ai/gateway/tools-invoke-http-api#security-boundary-important).

### Conversational requests and responses

OpenClaw routes inbound channel messages deterministically to an agent and session, then routes replies back through that channel. Direct messages share the main session by default; groups/rooms are isolated, and bindings select a configured agent. See [Channel routing](https://docs.openclaw.ai/provider-routing) and [Session management](https://docs.openclaw.ai/sessions).

Bind the chosen personal channel/account/peer explicitly to a dedicated Money Assistant agent. Restrict inbound DMs to the user's paired or allowlisted sender identity; OpenClaw does not process an unknown sender under the pairing policy until approved. See [Channel pairing](https://docs.openclaw.ai/channels/pairing). If any second person can reach the channel, configure per-channel/per-peer DM isolation rather than the shared default session.

The conversation flow is therefore:

1. OpenClaw authenticates/admit-lists the channel sender and routes the message to the Money Assistant agent.
2. The model interprets the question and invokes only the allowlisted Money Assistant tools it needs.
3. Laravel authenticates the plugin's service credential, enforces validation and authorization, performs all financial calculation/mutation, and records an audit entry.
4. The tool returns structured facts; OpenClaw writes and delivers the conversational response through the originating channel.

The Gateway's OpenResponses endpoint can also run an agent turn and maintain a session when explicitly enabled, but it shares the Gateway's full operator authentication boundary. It is useful for trusted headless clients, not necessary for this channel-first design. See [OpenResponses API: authentication and session behavior](https://docs.openclaw.ai/gateway/openresponses-http-api#authentication-security-and-routing).

## Laravel-facing contract constraints

The implementation specification should require the following:

1. **Narrow authentication.** Give the OpenClaw plugin a dedicated, rotatable Money Assistant service credential with explicit read/mutate abilities. Give Laravel a separate dedicated OpenClaw hook token. Neither side receives the Gateway operator token for routine integration.
2. **Private transport.** Keep both APIs on the host/private Docker network, loopback, or a tailnet. If traffic crosses hosts, use TLS and a trusted reverse proxy. Never publish the Gateway or Laravel service endpoints unauthenticated.
3. **Stable, task-shaped schemas.** Version the Laravel capability API. Use opaque IDs, ISO-8601 timestamps with zones, integer minor currency units, ISO 4217 currency codes, explicit reporting/original amounts, and machine-readable validation/error codes. Do not expose arbitrary URLs, queries, or database fields to the agent.
4. **Idempotent mutations and events.** Require a caller-generated idempotency key for every write and retain the prior result for safe retries. Deduplicate outbound events by `event_id`. Return a durable operation/audit ID from every accepted mutation.
5. **Server-side authority.** Laravel revalidates transaction state, permitted transitions, reconciliation, and requested category changes. Tool names, prompts, conversation history, and OpenClaw sender context are never substitutes for domain authorization.
6. **Minimal data egress.** Tool responses contain only what is required to answer the current question. Raw notification emails and receipt images remain in Money Assistant unless a later, explicit capability is approved. Hook events contain no transaction details.
7. **Auditing and correlation.** Record event ID, idempotency key, tool/capability, operation ID, OpenClaw agent ID, channel/sender context when available, outcome, and timestamps. Never log credentials or raw email bodies.
8. **Asynchronous delivery state.** Model notification intent, hook acceptance, OpenClaw completion, channel delivery, and user response as different states. Retry transient hook failures with backoff and surface an undelivered digest in the web UI; do not infer human approval from a delivered message.
9. **Bounded agent authority.** Allowlist only Money Assistant tools on the dedicated agent where practical. Mutation tools should expose the smallest useful action and fail closed. Do not grant shell, filesystem, Gateway-admin, cron-admin, or database capabilities merely to support this integration.
10. **Compatibility verification.** On the real server, verify the installed version, plugin SDK compatibility, mapped-hook schema, allowed agent, explicit delivery route, channel admission policy, and end-to-end idempotency before launch. Repeat the smoke test after OpenClaw upgrades.

## Surfaces explicitly not selected

- **Direct database access:** bypasses validation, audit, and the application's domain boundary.
- **Generic Gateway `/tools/invoke`:** always enabled, but shared token/password auth is full operator access; narrower scope headers are ignored for shared-secret auth, and sensitive tools are denied by default. See [Tools invoke API](https://docs.openclaw.ai/gateway/tools-invoke-http-api).
- **Gateway WebSocket RPC:** appropriate for trusted control clients and device pairing, but unnecessarily couples Money Assistant to OpenClaw's control protocol and scopes. See [Gateway protocol](https://docs.openclaw.ai/gateway/protocol).
- **OpenResponses/Chat Completions as event ingress:** disabled until configured and protected by the same operator boundary; a mapped hook is narrower and carries the right unattended-event semantics.
- **OpenClaw cron as financial policy:** technically capable, but it would duplicate reminder state and move business ownership out of Money Assistant.
- **Raw shell/skill scripts as the primary integration:** harder to schema, authorize, audit, and version than a native tool plugin backed by a Laravel capability API.

## Deployment verification checklist

Run these on the actual OpenClaw server without copying secret-bearing config into an issue or log:

- record `openclaw --version` and compare it with the plugin's declared minimum/peer version;
- run `openclaw gateway status --require-rpc` and `openclaw channels status --probe`;
- inspect the installed Money Assistant plugin runtime and confirm only intended tools are registered/allowlisted;
- confirm the mapped hook has a dedicated token, fixed agent, disabled caller session selection, and a private ingress;
- send one duplicate test event with the same ID and verify one user-facing digest;
- repeat one mutating tool call with the same idempotency key and verify one financial change/audit operation;
- test channel pairing/allowlisting, explicit proactive delivery target, failure handling, and secret-redacted logs.

## Primary sources

- [OpenClaw v2026.7.1 release](https://github.com/openclaw/openclaw/releases/tag/v2026.7.1)
- [Building plugins](https://docs.openclaw.ai/plugins/building-plugins)
- [Scheduled tasks and webhook ingress](https://docs.openclaw.ai/automation/cron-jobs)
- [Tools invoke API](https://docs.openclaw.ai/gateway/tools-invoke-http-api)
- [OpenResponses API](https://docs.openclaw.ai/gateway/openresponses-http-api)
- [Gateway protocol](https://docs.openclaw.ai/gateway/protocol)
- [Channel routing](https://docs.openclaw.ai/provider-routing)
- [Session management](https://docs.openclaw.ai/sessions)
- [Channel pairing](https://docs.openclaw.ai/channels/pairing)
