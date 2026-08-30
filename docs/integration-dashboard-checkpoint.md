# Integration dashboard review checkpoint

This is an intentionally unmerged review checkpoint for
[GoFormX #123](https://github.com/goformx/goformx/issues/123), not a completed
acceptance gate or production release. Implementation stops here at the user's
request, pending independent review.

## Baselines and ownership

- Control-plane base: `83b4d7bed646a6b91b33dae567085b0de3175075` (PR #16).
- Data-plane dependency: `9a73623d7aa194019a2d3c800e7c9d3f0b4eede1`
  (GoFormX PR #156). The cross-service workflow is pinned to this exact commit.
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

## Local verification

- `php vendor/bin/phpunit --no-coverage`: 163 tests, 1,405 assertions passed
  (configured Unit and Integration suites, not the separate acceptance test).
- `npm run build` and `npm run check`: generated asset matches; 11 UI tests pass.
- `npm run test:browser`: 17 tests pass using pinned Playwright Chromium.
- Strict site doctor: zero findings. Architecture contract: 1 test, 3 assertions.
- `composer validate --strict` and `git diff --check`: pass.
- `php bin/maintenance/site-verify`: fails at the generated acceptance test's
  `is_executable` assertion on native Windows, the existing upstream #2676
  limitation. This gate was not weakened. Linux hosted checks must be inspected.
- During verification, rejected numeric-key JSON objects masquerading as scope
  arrays, corrected route-test imports, and preserved the generated AGENTS.md
  end-of-file bytes outside its extension region.

## Remaining work and review concerns

1. New token/webhook browser workflows are covered with HTTP fixture responses
   and separately tested PHP composition. They have NOT yet been exercised as
   a complete browser -> PHP -> Go -> PostgreSQL lifecycle. Existing hosted
   cross-service suites do not by themselves close that gap. Verify real token
   revocation, tenant boundaries, audit rows and both credential classes.
2. Signed receiver examples, verification and replay-protection guidance remain
   #123/#124 work. A successful HTTP delivery is not proof of signature checking.
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
as proof. Keep #123 and the parent roadmap open; do not merge this checkpoint
or resume the goal without user direction.
