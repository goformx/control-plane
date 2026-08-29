# Development and deployment

## Repository contract

Run `composer check` after dependencies are installed. It performs strict Waaseyaa site diagnostics and the generated architecture and acceptance tests without network access. CI invokes this exact boundary.

For a fresh local database:

```bash
php vendor/bin/waaseyaa migrate
php vendor/bin/waaseyaa schema:sync
composer check
```

## Configuration

Runtime configuration is supplied through environment variables. `APP_URL` is the canonical public origin. `GOFORMX_API_BASE_URL` is the server-side data-plane origin. Production secrets are never committed and browser-delivered JavaScript must not contain assertion-signing or management credentials.

## Production topology

Cloudflare terminates public DNS. The Raspberry Pi ingress routes `goformx.com` to this Waaseyaa application and preserves `api.goformx.com` for the Go service. Application storage is a local SQLite volume included in the encrypted/offsite backup procedure. Deployments run migrations before traffic and retain the previous application artifact for rollback.
