# Integration operations workflow

This guide covers the owner/admin workflow in `/app` for external API tokens
and webhook delivery. GoFormX's Go data plane remains authoritative. For request
and response shapes, use the released [API/client guide](https://github.com/goformx/goformx/blob/main/docs/api-clients.md).
For receiver code and the signed-delivery contract, use the tested
[webhook receiver guide](https://github.com/goformx/goformx/blob/main/docs/webhooks.md)
and its linked TypeScript example.

Production signup, account recovery, and deployment remain separate release
gates. Publishing this guide does not by itself complete the self-service release
tracked in [#123](https://github.com/goformx/goformx/issues/123) or all documentation
acceptance in [#124](https://github.com/goformx/goformx/issues/124).

## Keep the three credentials separate

| Credential | Where it belongs | What it does |
| --- | --- | --- |
| Public form key (`gfpk_`) | Browser submission code | Submits to one published form. It is not a management credential. |
| External service token (`gfst_`) | A server, agent, or custom dashboard's secret manager | Calls only the organization-scoped operations granted by its selected scopes. Never embed it in public website code. |
| First-party assertion | Control-plane PHP to the Go data plane | Authenticates a short-lived internal management request. It is never shown to a user or external integration. |

Use the public integration example in the [forms workflow](forms-workflow.md)
for browser submissions. The rest of this guide concerns external service
tokens and webhook signing secrets.

## Issue and rotate an external service token

1. Sign in with a verified owner or admin account and choose **Manage API access**.
   Membership is rechecked on every request; a role change takes effect without
   relying on browser-supplied role data.
2. Give the integration a recognizable name, choose a lifetime from 1 to 365
   days, and select only its required scopes. No scope is selected by default.
   In particular, `submissions:read` exposes received data and `tokens:write`
   lets the caller issue and revoke tokens within its own authority.
3. Confirm the custody warning and choose **Create scoped token**.
4. At **Save this token now**, move the `gfst_` value directly into the
   integration's secret manager. This is the only reveal. The dashboard clears
   it after two minutes, on dismissal, when the tab is hidden, or when the form
   changes. Starting another server-backed integration request also clears it.
   Clipboard managers and downloaded files may retain extra copies; remove copies
   that are no longer needed.
5. Verify the integration using the canonical API/client guide. Token metadata
   shows an identifier, scopes, status, expiry, and last observed use; it can
   never recover the token value. The list is limited to 100 recent records and
   is not a complete historical inventory.

To rotate safely, create a replacement with the minimum required scopes, update
the consumer's secret custody, verify the replacement, then revoke the old token
from the metadata list. Revocation prevents new authenticated requests; do not
remove the old token first unless an immediate cut-off is intentional.

If issuance returns an uncertain outcome, do not click create again. Choose
**Reload token metadata**, identify and revoke any unclaimed credential, and
reconcile the consumer's custody. Only then choose **I have reconciled the change**
to unlock further mutations.

## Configure and operate a webhook

1. Select the form and choose **Load webhook** before creating or replacing an
   endpoint. A missing endpoint is an expected state, not permission to skip the
   reload.
2. Implement the tested receiver contract first: verify the signature over the
   raw request body with constant-time comparison, enforce the timestamp
   tolerance, and deduplicate the delivery ID. Install a 32–256 character
   signing secret in receiver-side secret custody.
3. Enter the public HTTPS destination, the complete write-only custom-header
   object, and the same signing secret. Choose whether to **Enable future deliveries when saving configuration**,
   confirm that the receiver is ready, then choose **Save complete webhook configuration**.
   Replacement is complete: omitted headers are removed.
   Destination paths, headers, and signing secrets cannot be read back.
4. Choose **Load delivery history** for the bounded recent window. A 2xx response
   acknowledges delivery but does not prove that the receiver verified the
   signature. Missing history does not prove that delivery never occurred.

**Pause future deliveries** stops future enqueueing only. Accepted deliveries
keep their original destination, headers, and signing secret and can still run.
**Resume future deliveries** does not backfill submissions accepted while paused.

For rotation, configure the receiver to accept both the current and replacement
keys, enter the replacement secret, confirm receiver readiness, and choose
**Rotate signing secret only**. New deliveries use the replacement; outstanding
and dead-letter snapshots keep the old secret. Retire the old receiver key only
after those snapshots can no longer be delivered or replayed under your
retention policy.

**Remove webhook endpoint** prevents new endpoint use but does not erase already
accepted delivery snapshots. Replay is offered only for dead letters, uses the
original delivery ID, payload, destination, headers, and secret, and requires
receiver-side ID deduplication.

## Reconcile failures before retrying

Reads can be reloaded. For mutations, use the dashboard's outcome rather than
assuming every error means the same thing:

- `401` from the browser session requires signing in again. A failed internal
  data-plane assertion is reported separately and commits no change.
- `403` means the current workspace role, the request's CSRF validation, or
  downstream authorization denied the operation. Refresh membership/context;
  if the session is otherwise valid, reload the page to obtain current CSRF
  state before trying a different action.
- `409` and `412` are definite concurrency rejections. Reload current metadata,
  compare it with the intended change, then submit a reconciled update.
- `503` is a definite no-op only when the dashboard identifies one of its exact
  recognized conditions: unavailable management audit, disabled webhook service,
  or unavailable service-token management. Fix that service availability before
  retrying. Treat any other `503` as uncertain.
- A transport interruption, malformed response, timeout, or unclassified server
  failure may have happened after the mutation committed. The dashboard blocks
  further mutations but leaves token revocation and metadata reload available.
  Reload both token and webhook metadata, revoke unclaimed credentials, compare
  durable state with the intended change, then explicitly acknowledge
  reconciliation.
- `429` is rate limiting. In the dashboard, wait before reloading. External API
  clients must honor `Retry-After` as described by the API/client guide; neither
  path should become a rapid retry loop.

Never expect token values, webhook headers, destination paths, or signing
secrets to be returned by a later read. Keep the receiver configuration and
external token values in their respective secret managers, and use dashboard
metadata only to reconcile identity, status, scope, expiry, and endpoint state.
