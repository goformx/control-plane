# Evidence and proposed decisions

Inspected September 30, 2026. The two adjacent public drafts state current limits; this file is implementation/review material, not public commitments.

## Verified behavior

Deployed sources: PHP `bb384f90cf995e1165cc57e121ebc4347a8913b2`, Go `b5308f5c2a6df7d86506a4adca21f5b2bfab4e1d`. Custody fixture repair and website/client drafts have not been deployed.

- Go OpenAPI exposes webhook-endpoint deletion and service-token revocation, but no form/submission/site/organization erasure operation. `repository/form/store.go`, `workspace_submissions.go` and `model/form_submission.go` persist accepted data. A soft-deleted form predicate is not an erasure API or scheduled purge.
- `OrganizationMembershipService::deleteAccount` refuses sole-owner deletion, revokes active memberships and invokes account repository deletion in SQLite. It does not call Go to erase organization data. `AuthLifecycleAuditListener` records account identifiers/action/disposition rather than passwords.
- `2026083002_submission_export_audit.up.sql` and `2026083003_management_audit.up.sql` prohibit UPDATE/DELETE/TRUNCATE. Audit target/subject identifiers remain; they are not anonymous merely because payloads are absent.
- `repository/form/webhook.go` deletes endpoint configuration; `2026082802_add_webhook_outbox.up.sql` stores independent encrypted delivery snapshots. Existing queued/dead-letter records remain. No time-based delivery/submission purge was found in the current runtime.
- `cmd/api/main.go` runs bounded expired-assertion-replay cleanup. This is security replay bookkeeping only. Revocation in `repository/token/store.go` updates revoked_at and records an audit, rather than deleting the token row.
- Read-only deployed inventory found `/opt/goformx-backups/initial-20260930/postgres.dump` (45,597 bytes, mode 600) and `php.sqlite` (1,224,704 bytes, mode 644), behind root-owned mode-700 parent directories. No backup contents or personal database rows were inspected.
- Deployed backup/restore script hashes match the local release package: backup `4dfb11b3e8a5a5b5268190b30254a0205f0dc931fcf5fe2917f0881cc743261c`; restore `a24c29ea7ea69b678c329390d188d4cdd6157ff32f6d20855dfc7bf042a99b3c`. Backup stops API/web writes, pg_dump + SQLite online backup, then restarts; failure leaves services stopped. Restore replaces both databases and restarts after ACL restoration. Neither applies backup encryption or retention/deletion suppression.
- No GoFormX backup timer or matching job file was found in the inspected systemd/cron directories. Existing unrelated backup timers were not changed. This bounded inspection does not prove absence of every external provider snapshot or operator copy.
- nginx: daily rotation, 14 rotated files, compression. GoFormX api/web/PHP containers: json-file logging with empty per-container options. No content was inspected. Cloudflare/DigitalOcean provider retention and host-level encryption are not verified.

## Concrete recommendation, awaiting owner adoption

| Category | Proposed policy | Implementation and evidence required before promising it |
| --- | --- | --- |
| Submissions and related delivery history | Default 90 days after acceptance; owner-selectable shorter retention, explicit longer periods only where justified | Tenant-scoped retention configuration, bounded purge worker, dependency traversal for receipts/delivery snapshots, paused/dead-letter handling, metrics and overdue alerts; verify late retries cannot recreate purged records |
| Verified erasure requests | Operational target of 7 days for active stores, with no guarantee until measured | Identity/authority checks, scoped request tracking, Go contract-first erase operation, account/organization coordination, audit minimization and verified completion receipt; no ad hoc runtime DB privileges |
| Backups | Daily paired backups, encrypted offsite; expire after 30 days | Chosen destination and protected key custody, approved scheduled job with write-pause behavior disclosed, alerts, integrity/restore tests, inventory/expiry worker; retire the initial snapshot under approved procedure |
| Restore safety | Reapply completed erasures before opening restored service | Independently retained minimal deletion ledger, trustworthy restore sequencing, isolated rehearsal proving erased data stays unavailable; ledger itself needs retention and privacy treatment |
| Application/security logs | 14-day target, payload/credential exclusion | Configure bounded Docker log rotation and measured age-based pruning, verify nginx behavior, redact identifiers where possible, document Cloudflare/provider records separately |
| Identifiable audit metadata | Review at 365 days; keep only what has a documented purpose | Owner purpose/legal review, reviewed privileged retention/anonymization design compatible with append-only security guarantees, schema/ACL migration and adversarial tests; do not disable audit triggers casually |
| Form schemas/workspace metadata | Keep while the workspace needs them; remove or minimize on verified closure | Define organization closure and ownership-transfer flow, resolve retained submissions/versions and token/webhook dependencies, test isolation and restore handling |

These numbers are recommendations, not existing behavior or accepted commitments. Until adopted and implemented, the public drafts intentionally state no automatic submission expiry and no complete erasure/backup deadline. An indefinite preview disclosure is honest but is not a completed data-minimization programme.

The [Canadian privacy regulator's retention/disposal guidance](https://www.priv.gc.ca/en/privacy-topics/privacy-for-businesses/appropriate-handling-of-personal-information/gd_rd_201406/) supports purpose-based retention and attention to associated copies/backups. It does not prescribe the proposed numbers or establish GoFormX compliance. Jurisdiction, provider-processing details and binding terms remain owner/legal review decisions.
