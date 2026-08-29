# GoFormX control plane

The human-facing account and management application for [GoFormX](https://goformx.com), built on [Waaseyaa](https://waaseyaa.org).

This repository owns browser sessions, accounts, organization membership, navigation, and human workflows. The Go service at `api.goformx.com` remains the source of truth for forms, schemas, publication, submissions, service tokens, and webhooks. This application never connects to the GoFormX data-plane database and never sends a privileged service credential to a browser.

## Local development

Requirements: PHP 8.5, Composer 2, SQLite, and Sodium.

```bash
composer install
php vendor/bin/waaseyaa install:init
composer check
composer dev
```

The application is served at `http://127.0.0.1:8080` by default. Local secrets are generated in `.env`; the file and SQLite database are ignored. The development fallback account is disabled by default and must never be enabled in a deployed environment.

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
