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

Runtime configuration is supplied through environment variables. `APP_URL` is the canonical public origin. `GOFORMX_API_URL` is the server-side data-plane origin. Production secrets are never committed and browser-delivered JavaScript must not contain assertion-signing or management credentials.

`WAASEYAA_AUTH_TOKEN_SECRET` optionally gives verification, reset, and invite tokens an independent HMAC key. When absent, Waaseyaa derives a purpose-scoped key from `WAASEYAA_APP_SECRET`; the application does not reuse `WAASEYAA_JWT_SECRET`.

Production registration defaults to `admin` (closed). For a new private account, an operator can run Waaseyaa's supported `user:provision-registered` command with the registered role and password supplied through bounded stdin JSON, then request the normal `POST /api/auth/resend-verification` flow for that email while registration remains closed. The account cannot log in until the recipient follows the emailed verification link. This requires qualified real outbound mail; never mark the database row verified directly or use a disposable token-capture fixture for production setup. Configure `SENDGRID_API_KEY`, `GOFORMX_MAIL_FROM_ADDRESS`, and an optional `GOFORMX_MAIL_FROM_NAME`, verify delivery, and only then provision the private account or set `GOFORMX_REGISTRATION_MODE=open`. Local development defaults to open registration and logs verification/reset URLs when mail is absent.

## Production image boot

The PHP image requires a migrated SQLite database on the persistent
`/app/storage` volume before serving traffic. Run the supported Waaseyaa
`install:init` command as a separate maintenance step against that same volume
after the backup and migration gates are accepted. The image entrypoint runs
`field-access:preflight --write-artifact` against the live database before it
starts PHP-FPM. Waaseyaa binds the result to the installed framework and schema.
If the database is absent, incompatible, or not ready, the container exits
without accepting requests. The artifact lives in the container's root-owned
`.waaseyaa` directory and is regenerated on every container start; it is not
copied from another database or preserved as a release artifact.

The FPM supervisor owns the preflight write and request workers run as
`www-data`. The web image's `/healthz` is only Nginx liveness. Deployment
readiness must route `/login` through Nginx and FPM and require HTTP 200, then
check application and database operations before sending public traffic.
The disposable smoke is `sh scripts/verify-proxy-cookie.sh` after both images
are built. A successful image smoke does not replace capacity, mail, restore,
migration, or rollback acceptance.

The `www` FPM pool has a 25-second request-termination setting, including
work after `fastcgi_finish_request()` and shutdown functions. This is a hard
backstop, with some scheduling delay before FPM kills the worker: a request in
progress can end abruptly,
and it is not a graceful deadline for the Go API or a promise of a complete
browser response. Nginx's 30-second FastCGI read timeout only limits idle
reads; a slow-drip response can otherwise run indefinitely. The routed
`sh scripts/verify-worker-bound.sh` smoke checks termination while FPM reads
a one-second upstream drip from a second disposable PHP container, recovery,
and a concurrent 8 MiB response. It mounts both test scripts at runtime; no
test endpoint is included in the production image.

## Production topology

Cloudflare remains authoritative for public DNS. The proposed private-release target is `fetder-droplet`, subject to capacity and operational acceptance. `www.goformx.com` is the canonical Waaseyaa UI and `api.goformx.com` is the Go service; apex behavior requires a reviewed compatibility decision before DNS changes. The Raspberry Pi remains a recovery dependency until an in-place droplet rollback is proven. Application storage is a local SQLite volume included in the encrypted/offsite backup procedure. Deployments run migrations before traffic and retain the previous application artifact for rollback.
