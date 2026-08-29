# ADR 0001: Separate the human control plane from the Go data plane

Status: accepted, 2026-08-29

## Decision

`goformx.com` is a Waaseyaa application responsible for browser sessions, accounts, organizations, navigation, and human workflows. `api.goformx.com` is the Go data plane and remains authoritative for forms, schema versions, publication state, submissions, service tokens, webhook endpoints, and webhook deliveries.

The control plane calls the documented GoFormX management API over HTTPS. It does not access the GoFormX PostgreSQL database, copy data-plane records into Waaseyaa as a second source of truth, or place management credentials in browser code. Browser actions are authorized by Waaseyaa and exchanged server-side for short-lived, audience-bound assertions carrying user, organization, scope, expiry, and request identity.

The OpenAPI document in `goformx/goformx` owns the cross-service contract. Changes are contract-first and compatibility-tested. The control plane may keep short-lived display caches, but those caches are explicitly disposable and cannot become an authorization source.

## Consequences

The two services can evolve and deploy independently. Tenant authorization must be enforced again by the Go service; a compromised or defective UI cannot select another organization. Cross-service integration tests and correlation identifiers are release requirements.
