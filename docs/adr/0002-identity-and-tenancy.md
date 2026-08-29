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

Every mutation requires an authenticated session and a matching `X-XSRF-TOKEN`. Entity lifecycle auditing records organization and membership changes with the acting account. No route issues, accepts, or returns a GoFormX data-plane credential.

Personal-workspace creation is deliberately lazy at the first verified context request rather than a best-effort email-verification side effect. The unique membership constraint makes retries idempotent, and the service removes an organization if its initial owner grant cannot be saved, so a transient write failure cannot leave an ownerless workspace.
