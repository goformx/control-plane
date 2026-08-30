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

Publish a future public key through `GOFORMX_ASSERTION_ADDITIONAL_JWKS` with state `next`. After every Go node has refreshed it, promote that key to the configured signer and publish the previous public key as `retiring`. Retain it for at least 65 seconds, then remove it. A compromised key is published as `revoked`; stop issuance until the replacement snapshot has reached every data-plane node.

The public key set is available at `/.well-known/goformx-control-plane-jwks.json`. Missing or malformed signing custody makes that endpoint return `503` and prevents management requests from being signed.

## Cross-service release gate

The `Cross-service boundary` workflow pins the accepted Go data-plane verifier commit, migrates disposable PostgreSQL and SQLite databases, and boots the real Go API and Waaseyaa HTTP entry point with an ephemeral Ed25519 key. Its PHP acceptance test signs in over HTTP with cookies and CSRF, proves an unauthenticated browser request stops at Waaseyaa, and resolves one application-owned organization for each verified account. It creates forms with one-use assertions and reads them through the browser-facing proxy. A second organization's form must remain invisible, and a forged active-organization selector must fail closed. The returned correlation identifier must appear in Go logs. Neither service's logs may contain assertions, signing custody, passwords, or form content; browser responses may contain authorized form data but never a privileged credential.
