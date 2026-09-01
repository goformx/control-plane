# Site contract

This application is governed by `.waaseyaa/site.yaml`.

Before changing application behavior:

1. Read the capability manifest and its active, planned, and excluded decisions.
2. Use the selected first-party Waaseyaa recipes and extension points.
3. Run `tests/Architecture/SiteContractTest.php`.
4. Run the strict site diagnostics.
5. Run `bin/maintenance/site-verify` without network access.

Generated files are owned by `.waaseyaa/generated.json`. Regeneration refuses edits outside the extension region below.

<!-- waaseyaa:extension:start local-guidance -->
## GoFormX authority boundaries

- This repository owns browser sessions, human accounts, application-owned organizations and memberships, navigation, and human workflows.
- `goformx/goformx` owns forms, Draft 2020-12 schemas, immutable schema versions, publication, submissions, service tokens, webhooks, and the canonical OpenAPI document at `goforms/contracts/openapi.v1.yaml`.
- Never connect PHP to the GoFormX PostgreSQL database or persist copies of forms and submissions as another source of truth.
- Resolve one authorized organization membership server-side before every management call. A browser-supplied organization identifier is input, never authority.
- First-party Waaseyaa assertions and external `gfst_` service tokens are separate credential classes defined by goformx/goformx#126. Neither may be exposed in browser-delivered code or logs.
  The #123 provisioning exception may reveal a newly issued external token once to its authorized owner/admin in a no-store response. It must never expose the credential used by the control plane, recover stored tokens, persist the reveal, or send it to telemetry. See ADR 0002.
- The Waaseyaa UI, third-party agents, and custom dashboards use the same documented business contract. Do not create an agent superuser or a second MCP-only business API.

## Agent roles

- Coding agents operate only in the local development plane unless a task explicitly authorizes deployment. Installed AI packages do not imply an exposed production endpoint.
- A future GoFormX product assistant acts as the authenticated user and resolved organization, calls GoFormX server-side, and cannot publish without the ordinary explicit publication operation.
- Third-party agents use documented API operations and scoped `gfst_` credentials exactly like other integrations.
- Treat generated schemas and patches as untrusted. Submission content must not enter a model without a separate privacy, consent, residency, retention, and audit decision.

## Required checks and traceability

1. Read `docs/adr/0001-control-plane-data-plane.md`, `docs/adr/0002-identity-and-tenancy.md`, and the relevant GoFormX issue before changing a boundary.
2. Update OpenAPI in `goformx/goformx` before implementing a cross-service contract change.
3. Run `composer check`; on native Windows invoke `php bin/maintenance/site-verify` after the database bootstrap commands in `README.md`.
4. Keep `config/waaseyaa.php` AI catalog and embedding providers disabled unless an issue explicitly defines the production capability and its security/privacy gate.
5. Link work to the control-plane roadmap at https://github.com/goformx/goformx/issues/84 and milestones 7 or 8.
<!-- waaseyaa:extension:end local-guidance -->