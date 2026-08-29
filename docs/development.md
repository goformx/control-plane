# Development and deployment

## Repository contract

Run `composer check` after dependencies are installed. It performs strict Waaseyaa site diagnostics and the generated architecture and acceptance tests without network access. CI invokes this exact boundary.

For a fresh local database:

```bash
php vendor/bin/waaseyaa install:init
composer check
```

`install:init` is required for a fresh application because it applies migrations, materializes entity tables, and activates the initial configuration generation as one operator boundary. `db:init` intentionally does not perform that final activation.

## Configuration

Runtime configuration is supplied through environment variables. `APP_URL` is the canonical public origin. `GOFORMX_API_BASE_URL` is the server-side data-plane origin. Production secrets are never committed and browser-delivered JavaScript must not contain assertion-signing or management credentials.

`WAASEYAA_AUTH_TOKEN_SECRET` optionally gives verification, reset, and invite tokens an independent HMAC key. When absent, Waaseyaa derives a purpose-scoped key from `WAASEYAA_APP_SECRET`; the application does not reuse `WAASEYAA_JWT_SECRET`.

Production registration defaults to `admin` (closed). Configure `SENDGRID_API_KEY`, `GOFORMX_MAIL_FROM_ADDRESS`, and an optional `GOFORMX_MAIL_FROM_NAME`, verify delivery, and only then set `GOFORMX_REGISTRATION_MODE=open`. Local development defaults to open registration and logs verification/reset URLs when mail is absent.

## Production topology

Cloudflare terminates public DNS. The Raspberry Pi ingress routes `goformx.com` to this Waaseyaa application and preserves `api.goformx.com` for the Go service. Application storage is a local SQLite volume included in the encrypted/offsite backup procedure. Deployments run migrations before traffic and retain the previous application artifact for rollback.
