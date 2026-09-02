# Schema-first forms workflow

This describes the `/app` workflow implemented for [#121](https://github.com/goformx/goformx/issues/121).
Production signup and deployment remain separate release gates; this document
does not claim that the apex is deployed or that account recovery is released.

## Create and publish

1. Sign in with a verified account. The server resolves your organization;
   its name and your current membership role appear above the forms list.
2. Choose **+ New form**, enter a stable name and a title, and list allowed
   browser origins one per line. An empty allowlist is not a wildcard.
3. Use the starter schema, paste a complete JSON Schema Draft 2020-12 form
   definition, or add simple fields with the optional helper. Advanced schemas
   stay in JSON; the bounded preview is illustrative, not a second validator.
4. Choose **Validate & create form**. Go validates the definition and returns
   actionable JSON Pointer errors. Invalid input stays in the editor.
5. Review the saved draft, then choose **Review publication** and confirm the
   exact version. Saving never publishes implicitly. Editing a saved snapshot
   and saving again creates a new immutable draft; the live version is unchanged.
6. Copy the public integration example. Supply values matching your schema and
   retain the same idempotency key and body when retrying one submission. Only
   the public `gfpk_` key belongs in a browser; no management token is needed.

Owner/admin memberships may write; members may read. The server rechecks
membership on every management request. These controls do not grant token,
webhook, submission, or membership-administration permissions.

## Work with an AI or your own dashboard

An AI can produce a candidate JSON Schema for you to inspect and paste. Treat
its output as untrusted: review field meanings, required values, numeric limits,
and personal-data collection before saving. Go enforces the same canonical
contract regardless of who generated the schema. Do not send real submissions
to a model as part of this workflow.

For direct automation, use the released [API/client guide](https://github.com/goformx/goformx/blob/main/docs/api-clients.md)
and its pinned contract artifacts. External agents and custom-dashboard servers
use an organization-scoped `gfst_` token held in server-side secret custody.
They use the same forms/version/publication operations as the human UI. There
is no agent superuser or alternate schema format. Follow the
[integration operations workflow](integrations-workflow.md) to issue and rotate
tokens, configure signed webhooks, and reconcile uncertain mutations. The full
self-service release remains governed by [#123](https://github.com/goformx/goformx/issues/123).

## Recover without losing edits

- Form details and schema drafts save separately. An ETag conflict keeps local
  edits; download/copy them, reload the server version, and reconcile explicitly.
- A network failure during a mutation may mean the operation succeeded. The UI
  blocks blind retries until you reload and reconcile server state.
- A failed form switch retains the previous form's complete editor snapshot.
- Unsaved work exists only in the current tab. Download it before closing,
  switching forms, signing out, or recovering an expired session.
- Use a current browser with native JSON source access and raw-number support.
  The startup check refuses unsupported runtimes; large numeric constraints and
  special JSON property names must not be silently rewritten.
- Archive/delete controls are not offered because the current business API does
  not expose those operations. The UI does not invent a parallel storage path.
