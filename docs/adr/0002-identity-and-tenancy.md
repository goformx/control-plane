# ADR 0002: Local identity with organization-scoped authorization

Status: accepted, 2026-08-29

## Decision

The first release uses `waaseyaa/auth` for registration, email verification, login, logout, password reset, session rotation, and two-factor authentication. OIDC remains an explicit migration seam; the current Biindigen integration is not production-ready and does not block delivery.

A Waaseyaa user is a human identity. A Waaseyaa group with the `organization` bundle is the tenant. Membership relationships carry roles. Every authenticated management request resolves exactly one organization membership before a data-plane call is constructed. A personal organization is created for a new verified account, while the model permits future team membership.

Human and organization identifiers are opaque stable strings at the service boundary. The Go data plane authorizes its own resource ownership from signed claims and repository predicates. It does not trust an organization identifier supplied independently by a browser.

Account deletion, organization departure, membership changes, and security events are audited. Deletion is suspended when the account is the sole owner of an organization until ownership is transferred or the organization is explicitly deleted.
