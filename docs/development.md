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

The private-use candidate defaults to `GOFORMX_REGISTRATION_MODE=open` and `GOFORMX_REQUIRE_VERIFIED_EMAIL=false`. Registration uses Waaseyaa's normal public signup route, and an unverified account may sign in and use its personal organization. Set registration mode to `admin` to close signup, or set `GOFORMX_REQUIRE_VERIFIED_EMAIL=true` to require verification before organization access. Waaseyaa alpha.302's `user:create` is an operator CLI command that creates a user with a hashed password and chosen role; it leaves email verification unset. The earlier `user:provision-registered` command is not present in locked alpha.302. Do not edit database fields or manufacture verification tokens.

Mail is optional for registration and login under the candidate policy. The normal forgot-password/reset flow still uses email, so it is the recovery path if an account password is lost. Alpha.302 has no local CLI password-reset command. Keep a newly assigned password in the user's authorized password manager during private-use setup. Configure `SENDGRID_API_KEY`, `GOFORMX_MAIL_FROM_ADDRESS`, and optional `GOFORMX_MAIL_FROM_NAME` only when email delivery is part of the chosen deployment. Do not log or capture live reset or verification tokens.

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
are built. A successful image smoke does not replace capacity, restore, or
rollback acceptance.

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

Cloudflare remains authoritative for public DNS. The proposed greenfield target is `fetder-droplet`. `www.goformx.com` is the canonical Waaseyaa UI and `api.goformx.com` is the Go service; the initial DNS change leaves apex unchanged. Russell confirmed there are no existing users or data to migrate, so this install uses fresh PostgreSQL and a persistent SQLite volume, with no Pi recovery dependency. Take consistent backups after initial writes, apply migrations before traffic and retain the previous working images. Host and DNS changes require deployment approval.
