# GoFormX control plane

The human-facing account and management application for [GoFormX](https://goformx.com), built on [Waaseyaa](https://waaseyaa.org).

This repository owns browser sessions, accounts, organization membership, navigation, and human workflows. The Go service at `api.goformx.com` remains the source of truth for forms, schemas, publication, submissions, service tokens, and webhooks. This application never connects to the GoFormX data-plane database and never sends a privileged service credential to a browser.

## Local development

Requirements: PHP 8.5, Composer 2, SQLite, Sodium, and Node.js 22 for UI development and verification.

```bash
composer install
npm ci
npx --no-install playwright install chromium
php vendor/bin/waaseyaa install:init
composer check
composer dev
```

The application is served at `http://127.0.0.1:8080` by default. Local secrets are generated in `.env`; the file and SQLite database are ignored. The development fallback account is disabled by default and must never be enabled in a deployed environment.

Set `GOFORMX_PUBLIC_API_URL` explicitly to the browser-reachable Go API origin
(HTTPS outside loopback). This controls public integration examples; it is
separate from the private server transport setting `GOFORMX_API_URL`.

`/app` contains the schema-first form editor. Owner/admin memberships can save
details, create immutable drafts, and explicitly publish. Members can read.
CodeMirror provides JSON highlighting, completion and undo; field assistance is
optional. Preview does not validate schemas or fetch references. Go remains the
validation authority. Form details and schema drafts save independently.
See the [forms workflow](docs/forms-workflow.md) for creation, publication,
AI/API integration, and recovery from conflicts or uncertain responses.

The editor requires a current browser supporting native JSON source access and
`JSON.rawJSON`; startup checks this before loading the workspace. Numeric
constraints retain their original precision, special property names remain
ordinary data, and duplicate keys are rejected. The lossless-json dependency
supplies only its numeric-safety predicate, not its parser or serializer.

Run `npm run build` after changing `ui/`; commit the generated public bundle.
`composer check` verifies the generated site contract, PHP suite, bundle drift,
UI model tests and isolated rendered-browser tests. Browser installation is a
bootstrap step, not hidden network work inside verification. Native Windows
still has the tracked framework executable-bit acceptance limitation (#2676);
the Linux CI gate runs the full command.

The separate cross-service workflow additionally runs `npm run test:live`
against real Waaseyaa sessions, Go HTTP handlers and disposable PostgreSQL.
It saves and reloads exact integer/decimal schema constraints through the editor,
rejects adjacent below-minimum public values, and retries a valid precise
submission. This guards the end-to-end numeric boundary from GoFormX #144;
browser-only codec tests cannot detect a rounding data plane.
The HTTP/custody and browser rehearsals use separate disposable environments,
so their loopback traffic does not share the framework's persistent per-IP
rate-limit budget. Both must pass the `authenticated-boundary` gate; production
rate limits remain enabled and unchanged.
Its verified-account fixtures do not replace the registration/reset/session
release gate in #118. No browser storage state, account passwords, assertions,
or raw credential-adjacent logs are published as test artifacts.

Open `/register` to create an account. Registration starts an authenticated but unverified session; `/api/control-plane/context` remains unavailable until the verification link has been used. The first verified dashboard request idempotently provisions the account's personal organization.

## Architecture

- [Control plane and data plane](docs/adr/0001-control-plane-data-plane.md)
- [Identity and tenancy](docs/adr/0002-identity-and-tenancy.md)
- [Retired prototype paths](docs/adr/0003-retired-prototypes.md)
- [Development and deployment](docs/development.md)

The canonical product capability declaration is [.waaseyaa/site.yaml](.waaseyaa/site.yaml). The provider-neutral release gate is `composer check`; GitHub Actions is only an adapter for that command.

Delivery is coordinated from [GoFormX roadmap issue #84](https://github.com/goformx/goformx/issues/84), including the [Waaseyaa control-plane foundation milestone](https://github.com/goformx/goformx/milestone/7).

## License

GPL-2.0-or-later
