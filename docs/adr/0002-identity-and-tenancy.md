# ADR 0002: Local identity with organization-scoped authorization

Status: accepted, 2026-08-29

## Decision

The first release uses `waaseyaa/auth` for registration, email verification, login, logout, password reset, session rotation, and two-factor authentication. OIDC remains an explicit migration seam.

This is not based on the stale `waaseyaa/oidc` README claim that the package is scaffold-only. Framework `main` on 2026-08-29 contains working authorization-code, token, refresh/revocation, userinfo, discovery, JWKS, consent, encrypted secret storage, migrations, and integration tests. The separate Biindigen application is still an April scaffold, the generic consumer-side OIDC provider planned in its handoff is absent, and RP-initiated logout remains deferred. Productionizing that end-to-end identity system is a distinct dependency and must not silently expand the first GoFormX control-plane release.

Revisit federation before public signup. Adopt Biindigen instead of local credentials when its deployment, consumer adapter, logout/session semantics, recovery, and end-to-end conformance tests are production-ready. The application identity model must use stable opaque subjects so this migration does not change organization ownership.

A Waaseyaa user is a human identity. The application owns an explicit organization, membership, role, and active-organization context boundary. Every authenticated management request resolves exactly one authorized organization membership before a data-plane call is constructed. A personal organization is created for a new verified account, while the model permits future team membership.

`waaseyaa/groups` is a bundle-aware content and member-directory primitive, not the tenancy authority. Its generic JSON:API CRUD and `administer groups` permission do not establish SaaS membership authorization. The application may use a dedicated organization bundle or retain a group identifier as a storage detail, but application policies own membership and active-tenant decisions. A raw group identifier is never accepted as proof of membership and never maps directly to a data-plane organization claim without policy resolution.

Human and organization identifiers are opaque stable strings at the service boundary. The Go data plane authorizes its own resource ownership from signed claims and repository predicates. It does not trust an organization identifier supplied independently by a browser.

Account deletion, organization departure, membership changes, and security events are audited. Deletion is suspended when the account is the sole owner of an organization until ownership is transferred or the organization is explicitly deleted.

## Implemented boundary

The application persists `goformx_organization` and `goformx_organization_membership` as non-discoverable, non-JSON:API content entities. Their indexed column storage supports membership lookup and a unique `(organization_uuid, user_id)` grant. Human-facing organization identifiers are UUIDs; integer storage keys never cross the application boundary.

The authenticated management boundary is:

- `GET /api/control-plane/context` resolves the session's selected organization or idempotently creates the verified account's personal workspace.
- `POST /api/control-plane/context/switch` treats `organization_id` as a selector and resolves it against the acting user's active memberships before changing the session.
- `POST /api/control-plane/organizations/leave` revokes membership, but refuses to orphan an organization whose only owner is leaving.
- `DELETE /api/control-plane/account` revokes memberships before deleting the user and refuses deletion while the user solely owns an organization.

Every mutation requires an authenticated session and a matching `X-XSRF-TOKEN`. Entity lifecycle auditing records organization and membership changes with the acting account. These context/account routes do not issue, accept, or return a GoFormX data-plane credential.

Personal-workspace creation is deliberately lazy at the first verified context request rather than a best-effort email-verification side effect. The unique membership constraint makes retries idempotent, and the service removes an organization if its initial owner grant cannot be saved, so a transient write failure cannot leave an ownerless workspace.

## Form-operation authorization

For the initial forms workflow, an active `member` can list/read forms and their immutable schema versions. Active `owner` and `admin` memberships may also create forms, update metadata, create new immutable versions, and explicitly publish a selected version. This is application policy, not a framework Groups permission or an inference from an authentication role. It does not grant token, webhook, submission, membership-administration, or account-deletion capabilities; those have separate policies and gates.

`FormOperation` binds each supported form operation to its canonical method, resource path, and single required scope. The production route provider and forms controller use that binding. Every request resolves membership again, checks the operation against the current organization role, and only then calls the server-side credential client. Browser-selected organization IDs, role headers, and credentials do not authorize the call. A demotion or membership revocation must take effect on the next request without requiring sign-out.

All browser mutations require the existing session CSRF token, including explicit publication. Metadata updates carry a single strong `If-Match` ETag; missing preconditions return `428`, and stale preconditions retain Go's `412`. PHP preserves JSON request bytes (including empty schema objects) and passes Go's structured validation errors back without introducing a second schema validator. These endpoints are the browser workflow boundary, not a claim that the editor UI or production self-service gate is complete.

## Submission-operation authorization

Submission content is a separate permission from form-definition access. Active
owners and admins may list, inspect, and export submissions; members may not.
`SubmissionOperation` owns this application policy. Every browser request resolves
the current membership before issuing only `submissions:read`; no browser token,
organization header, or role header supplies authority. Revocation/demotion takes
effect on the next request.

The control-plane routes mirror Go's list/detail/export paths under
`/api/control-plane/forms/{formId}/submissions`, plus its bounded recent delivery
read at `/api/control-plane/forms/{formId}/deliveries` (also `submissions:read`
in the canonical API, not webhook-configuration access). Export is POST and requires
session CSRF. Go owns strict cursor/filter/body validation, immutable accepted
schema projection, redaction, resource bounds, and durable preparation audit.
PHP preserves raw JSON numbers and repeated query/body fields for Go's validator,
never accessing the data-plane database or implementing another redactor.

Exports require a valid export UUID, JSON/CSV content type, and a declared
`Content-Length` exactly matching the fully received bounded body. Missing or
mismatched metadata fails without an attachment. This protects against the
current framework stream client's capped or interrupted reads (upstream
[Waaseyaa #2708](https://github.com/waaseyaa/framework/issues/2708)). The transport
allows fifteen seconds, leaving headroom beyond Go's ten-second export processing
deadline. Response filenames
are reconstructed from the validated UUID and media type; arbitrary upstream
headers, cookies, and credentials are not forwarded. Responses are no-store and
nosniff. No payload is logged or persisted by this controller.

This boundary targets Go development contract 1.2.0 and is not a production
release claim. The submissions UI renders actual redacted values with titles/types
from the exact accepted schema, retaining arbitrary JSON and exact numbers. It
does not apply defaults, fetch remote schema references, or reinterpret old data
against the current version. Recent deliveries are matched by submission ID;
absence from that bounded window never means no historical delivery occurred.

The UI retains data and cursors only in memory, clears content when the form
changes or the page becomes hidden, and rejects stale asynchronous results.
Filters are never added to browser navigation/history. Exports use applied filters
across the whole bounded result set, not merely the visible page. GoFormX #122
requires live session-to-API tests for these boundaries before completion.

## Integration operations and one-time provisioning

Active owners and admins may list/create/revoke external service tokens and
read/configure/pause/resume/rotate/delete webhooks or replay a dead-letter delivery.
Members may not perform these operations. `IntegrationOperation` owns token and
webhook-configuration mutations plus replay. The bounded delivery-history read
remains `SubmissionOperation::Deliveries` because the canonical API assigns it
`submissions:read`; it applies the same owner/admin policy and fresh membership
resolution. Every mutation requires session CSRF. Browser credential, role and
organization headers are not authority. Go checks resource ownership again.

Token creation is delegation, not a blanket privileged assertion. The server
validates the selected scopes against the canonical enum, rejects duplicates and
unknown scopes, and signs only `tokens:write` plus the selected scopes. Go remains
the authority for token validation, persistence, audit, expiry and revocation.
`tokens:write` is an explicitly selectable powerful scope: integrations holding it
can mint/revoke tokens within their own authority. Admins share this integration
authority with owners; this does not grant application membership administration.

The sole browser credential exception is the freshly created external token from
that explicit creation request. A typed projection validates its format, lookup
ID, organization and active metadata. Only this operation can return a `token`
field; metadata reads cannot recover it. Responses are no-store/no-cache/nosniff
and forward no arbitrary upstream headers or error bodies. PHP does not persist
token values, destination paths, custom headers or signing secrets. The control
plane's assertion and signing custody never cross into the browser.

The UI requires acknowledging the one-time copy/download warning before creation,
and removes the reveal on dismissal, page hiding/navigation, form change, any
subsequent integration action and a two-minute timeout. It uses neither browser
storage nor telemetry. Copy/download are explicit actions; clipboard managers and
downloaded files are outside the application's deletion control. An interrupted
creation is an uncertain outcome, not permission to automatically issue another
token. Reload metadata, revoke any unclaimed credential, and explicitly reconcile
before creating again.

Webhook PATCH is ordinary JSON, unlike form metadata merge-patch; media types are
operation-selected. Pause prevents future enqueueing only. Existing delivery
snapshots keep their destination, headers and signing secret after endpoint
rotation/deletion. Receivers must maintain old/new key overlap for outstanding
deliveries and retained dead-letter replay. No operation claims an HTTP 2xx proves
that the receiver verified its signature. The canonical repository now publishes
a tested signed receiver example. The exact installed release and remaining
self-service verification remain #123/#124 release work.
