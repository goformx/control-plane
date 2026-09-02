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
5. Review the saved draft, open **Publish**, then choose **Review publication**
   and confirm the exact version. Saving never publishes implicitly. Editing a saved snapshot
   and saving again creates a new immutable draft; the live version is unchanged.
6. Open **Connect** and copy the public integration example. Supply values matching your schema and
   retain the same idempotency key, schema-version header, and body when retrying
   one submission. Only the public `gfpk_` key belongs in a browser; no management
   token is needed.

Owner/admin memberships may write; members may read. The server rechecks
membership on every management request. These controls do not grant token,
webhook, submission, or membership-administration permissions.

## Submit and review accepted data

1. Open the published form, choose **Connect**, and review **Connect your website**. Add the website's
   origin under **Allowed browser origins** before embedding. An empty list blocks
   cross-origin browser submissions. Choose **Save details**; origin changes apply
   immediately and do not require republishing the schema.
2. Choose **Copy JavaScript example** and replace only the example values with
   data that satisfies the published schema. The rendered example is generated
   from the selected form's public key, submission endpoint, and current schema
   version; it does not contain a management credential.
3. Use a new idempotency key for a new logical submission. If the response is
   interrupted and the same submission must be retried, retain both the original
   `Idempotency-Key` header, the exact `X-GoFormX-Schema-Version` header, and the
   exact request body. Reusing a key with a different body or schema version
   returns `409` `idempotency_conflict`; it does not mean the changed submission
   was accepted. A changed submission requires a new key.
4. After the API accepts the submission, return to the same form, choose
   **Submissions**, then **Load submissions**. Only owners and admins can view received content.
   **Apply submission filters** can narrow the list by received time, acceptance
   status, or exact accepted schema version.
5. Choose a submission row to inspect its ID, request ID, accepted timestamp,
   lossless values, redacted paths, and **Exact accepted JSON Schema**. The detail
   uses the version that accepted this row even if a newer schema is now live.
   Recent webhook history is a bounded form-level window; an absent entry does
   not prove the submission was never delivered.
6. Use **Export JSON** to preserve exact numeric values and structure for fields
   retained under the accepted version's sensitive-data redaction policy. Use
   **Export CSV** for initial spreadsheet viewing. Exports use the applied filters
   across at most 1,000 rows / 8 MiB, not only the visible page, and are audited.
   Keep exported personal data in appropriate custody. Spreadsheet edits or
   re-saves can remove the CSV cell protections.

An equivalent public browser request and this review path run in the real
cross-service browser gate; fixture tests separately verify the rendered example's
generated endpoint, version, and credential boundaries. For server-side submission
clients and canonical request semantics, follow the
[API/client guide](https://github.com/goformx/goformx/blob/main/docs/api-clients.md)
instead of translating dashboard behavior into a separate contract.

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
