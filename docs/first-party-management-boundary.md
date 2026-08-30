# First-party management boundary

The browser authenticates only to Waaseyaa. After resolving the verified account and its active application-owned organization membership, the control plane creates one Ed25519 assertion for one outbound GoFormX management request. The assertion is never returned to browser code, stored in a session, or logged.

## Local bootstrap

Run `php bin/maintenance/goformx-generate-signing-key`, then place `GOFORMX_ASSERTION_KEY_ID` and `GOFORMX_ASSERTION_SIGNING_SEED` in `.env.local`. Configure the Go API with the emitted `GOFORMX_DATA_PLANE_JWKS_SNAPSHOT` and enable its first-party verifier. The seed is private; the JWK and snapshot contain only public material.

The accepted values are fixed:

- issuer: `https://goformx.com`
- audience: `https://api.goformx.com`
- algorithm: EdDSA with Ed25519
- protected type: `gofx-fpa+jwt`
- assertion lifetime: 60 seconds

Local API transport may use an explicit loopback HTTP origin. Non-loopback deployments require HTTPS.

## Rotation

Publish a future public key through `GOFORMX_ASSERTION_ADDITIONAL_JWKS` with state `next`. After every Go node has refreshed it, promote that key to the configured signer and publish the previous public key as `retiring`. Retain it for at least 65 seconds, then publish it as `revoked` and prove rejection before optionally omitting it from live discovery. Keep revoked tombstones in the Go deployment and rollback snapshots; simple removal does not defend against a stale publisher or cold-start snapshot.

The configured signer is always published as `active`; additional keys cannot duplicate its ID. During emergency recovery, disable first-party acceptance in Go first, replace compromised signing custody, then publish the old public key as `revoked` through `GOFORMX_ASSERTION_ADDITIONAL_JWKS`. Restore acceptance only after the replacement and revoked-key snapshots have reached every Go node. Never reuse a revoked key ID or restore an older snapshot that loses a revocation.

Follow the [canonical data-plane rotation runbook](https://github.com/goformx/goformx/blob/main/docs/runbooks/first-party-key-rotation.md). Its automated HTTPS/HTTP/PostgreSQL drill proves verifier transitions, stale-response handling, and persistent replay checks, but does not prove that production custody was rotated or every deployed node refreshed. Record the real 65-second drain and deployment/recovery observations under goformx/goformx#120 and #125.

### Disposable custody and process rehearsal

The `Cross-service boundary` workflow also runs `tests/CrossService/CustodyRotationTest.php` against the pinned Go binary and disposable PostgreSQL/SQLite databases. It generates private seeds through `bin/maintenance/goformx-generate-signing-key`, passes them only to PHP child processes, and resolves the real configured `FirstPartyAssertionIssuer` through the application container. A CLI-only test fixture captures assertions over a private pipe; no signing endpoint is added to the application.

The rehearsal checks the actual PHP public JWKS endpoint while replacing signer configuration, restarts the actual Go executable from those public snapshots, and measures a full 65-second retiring-key overlap. It proves that an unused old assertion expires before retirement, that a fresh assertion from the revoked key fails, that disabling first-party acceptance leaves a scoped service token working, and that replacement custody plus revoked-key snapshots preserve revocation and replay consumption across Go process restarts during a discovery outage. Replacement-key probes also require wrong-scope rejection and cross-organization denial for an actual form. Child logs are checked for seeds, assertions, and the service token, then removed; phase timestamps and elapsed time are safe CI output.

Run only against disposable local services. The workflow is the reproducible setup: migrated PostgreSQL `goformx` on loopback port 5432 with its fixture credentials, a separately initialized `WAASEYAA_DB`, PHP 8.5 with Sodium, and the Go executable built from the workflow's pinned revision. Ports 18092 and 18093 must be unused. After that setup:

```sh
APP_ENV=local GOFORMX_CUSTODY_REHEARSAL=1 \
GOFORMX_ROTATION_API_BINARY=/absolute/path/to/goformx-api \
GOFORMX_ROTATION_DATA_PLANE_ROOT=/absolute/path/to/goformx/goforms \
WAASEYAA_DB=/absolute/path/to/disposable-control-plane.sqlite \
php vendor/bin/phpunit tests/CrossService/CustodyRotationTest.php --no-coverage
```

This is a **snapshot-based, single-node local rehearsal**, not a production rollout. The PHP publication probe uses loopback HTTP; successful HTTPS discovery, stale responses, and overlapping refreshes are covered separately by the Go tests. Production still requires protected secret provisioning, TLS discovery, per-node refresh/restart evidence, incident audit observations, and validated rollback snapshots under #120/#125. Never copy the fixture seeds, shared test passwords, or test application secrets into a deployment.

The public key set is available at `/.well-known/goformx-control-plane-jwks.json`. Missing or malformed signing custody makes that endpoint return `503` and prevents management requests from being signed.

## Cross-service release gate

The `Cross-service boundary` workflow pins the accepted Go data-plane verifier commit, migrates disposable PostgreSQL and SQLite databases, and boots the real Go API and Waaseyaa HTTP entry point with an ephemeral Ed25519 key. Its PHP acceptance test signs in over HTTP with cookies and CSRF, proves an unauthenticated browser request stops at Waaseyaa, and resolves one application-owned organization for each verified account. It creates forms with one-use assertions and reads them through the browser-facing proxy. A second organization's form must remain invisible, and a forged active-organization selector must fail closed. The returned correlation identifier must appear in Go logs. Neither service's logs may contain assertions, signing custody, passwords, or form content; browser responses may contain authorized form data but never a privileged credential.
