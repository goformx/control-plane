# Integration dashboard review checkpoint

This records the merged dashboard slice and the follow-up evidence for
[GoFormX #168](https://github.com/goformx/goformx/issues/168), not a completed
acceptance gate or production release. The remaining cross-service evidence is
being added in bounded, reviewable slices.

## Baselines and ownership

- Control-plane base: `1a4d4d5` (merged PR #17).
- Data-plane dependency: `c904fe1e0d70b7af6a62ebe2704582510ce8f1ae`
  (GoFormX PR #178). The cross-service workflow is pinned to this exact commit.
- Related merged backend work: GoFormX #153 submission export, #154 storage-key
  rotation, #155 atomic token audit, #156 atomic webhook lifecycle audit.
- PHP owns sessions and fresh organization membership resolution. Go owns
  machine credentials, webhook configuration, delivery snapshots and audits.

## Included here

- Owner/admin token metadata, selected-scope issuance, one-time reveal and
  revocation; server-side organization authority and CSRF checks.
- Metadata-only webhook reads; complete replacement, narrow pause/rotation
  patches, deletion, bounded delivery metadata and dead-letter replay.
- Explicit JSON media types: form merge patches remain merge-patch JSON;
  webhook lifecycle patches are ordinary JSON.
- Secret-free response projection except the intentionally new external token;
  no-store responses, reveal disposal, stale-operation invalidation and
  uncertain-outcome reconciliation controls.
- PHP route composition/role/CSRF/projection tests and browser regression tests.
- A rendered owner workflow through real PHP sessions and the pinned Go process
  issues and revokes a scoped token, proves direct API access before revocation
  and rejection afterward, and runs webhook create/pause/resume/secret-rotation/
  delete against disposable PostgreSQL.
- The same workflow publishes a form and sends real deliveries to an ephemeral
  HTTPS receiver using the published TypeScript verifier. It proves signature
  and timestamp rejection cases from a captured production delivery, retry
  deduplication, old-key dead-letter replay after rotation, and new-key delivery.
  A separate read-only database fixture verifies immutable delivery snapshots,
  terminal token/webhook state and unique management audit IDs after deletion.
- A second live session proves foreign-workspace isolation, member denial despite
  a forged role header, CSRF enforcement after promotion, next-request promotion,
  demotion and revocation, in-memory metadata purge after role loss, and anonymous
  denial immediately after the disposable account is externally deleted. A
  before/after data-plane query proves denied transitions did not mutate state or
  append management audits.

## Local verification

- `php vendor/bin/phpunit --no-coverage`: 175 tests, 1,466 assertions passed
  (configured Unit and Integration suites, not the separate acceptance test).
- `npm run build` and `npm run check`: generated asset matches; 11 UI tests pass.
- `npm run test:browser`: 21 tests pass using pinned Playwright Chromium.
- Strict site doctor: zero findings. Architecture contract: 1 test, 3 assertions.
- `composer validate --strict` and `git diff --check`: pass.
- `php bin/maintenance/site-verify`: fails at the generated acceptance test's
  `is_executable` assertion on native Windows, the existing upstream #2676
  limitation. This gate was not weakened. Linux hosted checks must be inspected.
- During verification, rejected numeric-key JSON objects masquerading as scope
  arrays, corrected route-test imports, and preserved the generated AGENTS.md
  end-of-file bytes outside its extension region.
- Independent review corrections preserve definite downstream mutation outcomes,
  keep data-plane assertion failures separate from PHP session expiry across both
  integration and delivery-history routes, purge webhook state after role loss,
  and retain validated trace/rate-limit response headers.

## Remaining work and review concerns

1. The initial browser -> PHP -> Go -> PostgreSQL token and webhook lifecycle is
   covered, including real token authentication/revocation, signed delivery,
   receiver verification, retry/replay, authorization changes and independent
   audit counts. Still extend the gate with failure/uncertain-outcome paths and
   broader credential-lifetime evidence before treating #168 as complete.
2. The published #124 receiver example is now exercised by the canonical live
   gate. Keep receiver operations guidance current as delivery behavior evolves;
   a generic successful HTTP delivery is still not proof of signature checking.
3. Adversarial async probes need broader coverage: hidden tabs, logout, rapid
   form switching, stale responses, role changes and partial mutation responses.
   Current browser regressions cover only a subset. Do not infer comprehensive
   secret-lifetime guarantees from the green fixture tests.
4. The UI limits token history to 100 recent records. Assess reconciliation when
   an issued token falls outside that window, and the usability of explicit
   acknowledgement after an uncertain mutation.
5. Framework `0.1.0-alpha.299` remains pinned. Verify released inclusion of auth
   fixes #2696/#2703 before #118 acceptance; production construction debt #2697
   and bounded HTTP response truncation #2708 remain upstream concerns. The
   existing export length guard does not fix generic framework transport.
6. GoFormX #151 JSON-versus-JSONB debt, #120 production credential custody and
   boundary evidence, and #125 deployment/restore/capacity gates remain open.
   No production deployment, DNS or vault changes were made here.

Review the actual source and canonical OpenAPI rather than accepting this note
as proof. Keep #123 and the parent roadmap open until their remaining acceptance
evidence is complete.
