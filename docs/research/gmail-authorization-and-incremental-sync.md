# Gmail authorization and incremental synchronization

Research date: 2026-07-22

## Recommendation

The MVP should connect the dedicated Gmail account through Google's OAuth 2.0 web-server authorization-code flow, request only `https://www.googleapis.com/auth/gmail.readonly`, and request offline access. The application should poll `users.history.list` every minute, treat Gmail message IDs as the ingestion idempotency key, and fall back to a bounded `messages.list` reconciliation whenever the history cursor expires.

This keeps Gmail access read-only while still allowing the application to read message bodies. The narrower `gmail.metadata` scope is insufficient because it excludes message bodies, and `messages.list` does not allow its `q` filter with that scope. `gmail.readonly`, `gmail.metadata`, and `gmail.modify` are all classified as restricted scopes, so `gmail.readonly` is the least-privileged scope that satisfies the requirement. [Google: Choose Gmail API scopes](https://developers.google.com/workspace/gmail/api/auth/scopes) [Google: `users.messages.list`](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/list)

## Authorization and token lifecycle

The specification should require:

- A Google Cloud project with the Gmail API enabled and a web-application OAuth client. The app redirects the owner to Google, validates the returned `state` value, exchanges the one-time code on the server, and registers an exact callback URI. Google's web-server flow is intended for applications that can securely store confidential credentials and maintain state. [Google: OAuth 2.0 for web-server applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- `access_type=offline` on the initial authorization request. This is required for unattended scheduled access and yields a refresh token on the first code exchange. Persist the refresh token, client secret, and any current access token encrypted at rest; never expose them to the browser or logs. Use Google's maintained PHP client rather than implementing token exchange and refresh manually. [Google: Gmail server-side authorization](https://developers.google.com/workspace/gmail/api/auth/web-server) [Google: OAuth 2.0 for web-server applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- The OAuth consent screen should be moved to **In production**, even though this remains a one-user personal app. An external project left in **Testing** issues refresh tokens that expire after seven days when Gmail scopes are requested. Google says personal-use apps with fewer than 100 users do not have to complete verification, but the owner will have to pass through the unverified-app warning. All Google API user-data policies still apply. [Google: OAuth refresh-token expiration](https://developers.google.com/identity/protocols/oauth2#expiration) [Google: When verification is not needed](https://support.google.com/cloud/answer/13464323)
- A visible connection health state: connected account address, granted scope, last successful poll, and a reauthorization-required error. Refresh tokens can stop working after revocation, six months of disuse, a password change when Gmail scopes are present, or token-count limits. A failed refresh must pause ingestion, preserve the cursor, and ask the owner to reconnect; it must not silently start a new baseline. [Google: OAuth refresh-token expiration](https://developers.google.com/identity/protocols/oauth2#expiration) [Google: Gmail error handling](https://developers.google.com/workspace/gmail/api/guides/handle-errors)

Service-account access should not be part of the MVP. Accessing Workspace user data with a service account requires a Workspace administrator to grant domain-wide delegation; a normal dedicated Gmail account should use user OAuth consent. [Google: OAuth 2.0 for server-to-server applications](https://developers.google.com/identity/protocols/oauth2/service-account)

## Initial connection

The connection process should establish a no-history-before-connection boundary without creating a race:

1. Persist a `connected_at` timestamp immediately before starting the mailbox bootstrap.
2. Read `users.getProfile` and persist its current `historyId` as the initial cursor. The profile response explicitly exposes the mailbox's current history record. [Google: `users.getProfile`](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/getProfile)
3. Run a bootstrap `messages.list` query using `in:anywhere after:<epoch-seconds>`, with a small overlap before `connected_at`, paginate every result, and fetch each result with `messages.get(format=FULL)`. Post-filter on Gmail `internalDate >= connected_at` so the search overlap cannot import older mail. Epoch seconds avoid Gmail's PST-midnight interpretation of date-only searches. `messages.list` returns only IDs and thread IDs, so a `messages.get` call is needed for content. [Google: Search and filter messages](https://developers.google.com/workspace/gmail/api/guides/filtering) [Google: List Gmail messages](https://developers.google.com/workspace/gmail/api/guides/list-messages)
4. Process bootstrap results idempotently. Messages arriving after the profile read may appear both in this bootstrap and in the next history poll; the unique Gmail message ID makes that safe.

This imports notifications from the moment of connection forward, but not older mail that happened to be in the account.

## Minute-by-minute incremental sync

Each mailbox connection should have at most one sync worker running. Every minute it should:

1. Call `users.history.list(userId=me, startHistoryId=<stored cursor>, historyTypes=messageAdded)`.
2. Follow every `nextPageToken`. Read `messagesAdded`, not the generic `messages` collection, because Google notes that the generic collection can duplicate entries represented by the specific change fields.
3. De-duplicate message IDs within the poll and fetch unseen IDs with `messages.get(format=FULL)`. The Gmail message resource defines `id` as immutable and exposes the parsed MIME tree in `payload`; `internalDate` is the reliable Gmail-acceptance timestamp for normal SMTP delivery. [Google: Gmail message resource](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages)
4. Durably record or enqueue every fetched message before moving the cursor. Only after the final page is durable should the app atomically advance to the response's top-level `historyId`. Google says history is ordered by increasing, non-contiguous IDs and that the final response cursor should be stored once no `nextPageToken` remains. [Google: `users.history.list`](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list)

Do not use unread state as an ingestion boundary: the owner or Gmail could change it before a poll. Do not apply a Gmail “processed” label in the MVP, because that would require the broader `gmail.modify` scope. Local ingestion state is authoritative.

The incremental request should not be restricted to `INBOX`. Poll all `messageAdded` events and filter locally so notifications routed to another Gmail category, archived by a rule, or placed in spam are still recoverable.

## Filtering and duplicate protection

Filtering should be layered:

- The dedicated mailbox is the coarse boundary.
- Deterministic local adapters should allowlist known forwarding sources and recognize known bank/payment notification templates before extracting data.
- Unrecognized messages should be retained locally as ignored/unparsed ingestion records with a reason; they should not become financial transactions or be sent to AI.
- Sender and subject are routing hints, not proof. The adapter should validate the expected body structure and required financial fields.

Use a database unique key on `(gmail_connection_id, gmail_message_id)`. Gmail documents the message ID as immutable, making it the correct key for poll retries, overlapping reconciliation windows, and duplicate IDs appearing across history records. [Google: Gmail message resource](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages)

A separately forwarded copy can have a different Gmail message ID, so message-level idempotency cannot prove transaction-level uniqueness. When the notification contains a bank authorization/reference ID, store a second unique source key scoped to the account/source. Without such an identifier, compute a conservative fingerprint from source account, amount, currency, transaction time, and normalized merchant, but flag close matches for review rather than automatically discarding them: two legitimate equal-value purchases can occur close together. This second rule is an application-domain safeguard, not a Gmail guarantee.

## Recovery and reconciliation

The specification should distinguish these cases:

- **Expired history cursor:** Gmail history is typically available for at least a week but can be available for less time; an invalid or old `startHistoryId` typically returns HTTP 404. On that response, capture a new `users.getProfile.historyId` **before** recovery, then run paginated `messages.list` bounded from the last successful sync time (with overlap) and never earlier than `connected_at`. Process all results idempotently and set the cursor to that pre-recovery history ID only after the scan is durable. Changes after that ID remain available to the next incremental poll, while changes also seen by the full scan are harmless duplicates. [Google: Synchronize clients with Gmail](https://developers.google.com/workspace/gmail/api/guides/sync) [Google: `users.history.list`](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list) [Google: `users.getProfile`](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/getProfile)
- **Routine safety net:** Once daily, list `in:anywhere` messages over an overlapping recent window (seven days is suitable for the MVP) and re-run them through the same idempotent path. This is cheap for the dedicated low-volume mailbox and covers scheduler interruptions and implementation mistakes without altering Gmail.
- **Transient API or network failure:** Retry 429/rate-limit and 5xx failures with capped exponential backoff plus jitter. Leave the cursor unchanged until a complete successful pass. Google's Gmail guidance explicitly recommends exponential backoff for rate-limit and server errors. [Google: Gmail error handling](https://developers.google.com/workspace/gmail/api/guides/handle-errors) [Google: Gmail usage limits](https://developers.google.com/workspace/gmail/api/reference/quota)
- **401:** Refresh the access token. If refresh fails, mark the connection as requiring reauthorization and notify through the application's normal reminder channel. [Google: Gmail error handling](https://developers.google.com/workspace/gmail/api/guides/handle-errors)
- **Malformed or unsupported email:** Record the failure independently of the mailbox cursor and put it in an operational review state. One bad message must not permanently block later mailbox history.

Operationally, retain the last attempted and successful sync times, current cursor, pages and messages examined, ignored-message counts, parse failures, retry count, and latest error. Alert when the normal two-minute appearance objective is breached or reauthorization is required.

## Resulting MVP requirements

1. One owner-authorized Gmail connection using OAuth web-server flow, offline access, and only `gmail.readonly`.
2. Encrypted server-side token storage and explicit reconnect behavior.
3. One-minute serialized `history.list` polling over `messageAdded` events.
4. Durable processing before cursor advancement, with immutable Gmail message IDs as idempotency keys.
5. Local deterministic filtering and parsing; no Gmail mutation and no unread-state dependency.
6. Bootstrap from connection time, daily overlapping reconciliation, and automatic full recovery from history-cursor 404s.
7. Conservative transaction-level duplicate detection that never merges ambiguous equal purchases without review.
8. Observable connection/sync health and notification when ingestion stalls or authorization fails.
