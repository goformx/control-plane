# GoFormX data retention and backup notice

Draft for review, September 30, 2026. Operator: Russell Jones. Contact: [jonesrussell42@gmail.com](mailto:jonesrussell42@gmail.com).

## Current retention

Accepted submissions, forms and immutable schema versions currently have no automatic age-based deletion schedule. Delivery history also has no implemented time-based purge. Management and export audits are append-only; their normal application/database runtime cannot update, delete or truncate them. Token records are marked revoked or expire for authorization purposes, rather than being automatically erased.

Short-lived assertion replay records have a separate expiry cleanup. That security mechanism does not delete forms, submissions, account history or backups.

The host's nginx logs are configured for daily rotation with 14 rotated files. This is a rotation setting, not a guarantee that every log or provider-held record disappears after 14 days. The inspected application containers use Docker JSON-file logging without a configured per-container rotation cap. No uniform verified retention period currently covers those logs or external infrastructure records.

## Deletion requests

Contact the operator about a specific account, workspace, form or submission. Do not include access credentials. Account deletion, leaving a workspace, hiding a record, revoking a token and deleting a webhook endpoint are different operations; none should be treated as complete erasure of all related data.

The current preview does not offer self-service deletion of individual submissions or complete organization erasure through its public API. There is no implemented deletion deadline, backup-erasure deadline or end-to-end deletion receipt. Operator handling requires verification of the requester and review of related data and retained audit records.

## Backups and recovery

An initial paired database backup was taken on September 30, 2026. It is stored on the deployment host. The inspected GoFormX package provides an operator-invoked paired PostgreSQL/SQLite backup process; it does not configure recurring GoFormX backups, backup expiry or an offsite destination. Do not rely on a promised daily backup, fixed recovery point or recovery time.

The backup scripts create database snapshots protected by host file/directory access. They do not apply backup-file encryption. An encrypted offsite backup arrangement has not been verified. The initial backup predates the subsequently verified personal-site enquiries and is not evidence that those later writes are backed up.

Restoration replaces the active databases with an earlier snapshot. A restored snapshot can contain information removed after that snapshot was taken. There is no verified deletion-suppression ledger that reapplies completed erasures before a restore is made available. We therefore cannot promise immediate or permanent removal from every backup.

Exported files and webhook receivers' copies are outside the active GoFormX database. Their owners must manage those copies separately. A successful export or delivery does not transfer control of every subsequent copy back to GoFormX.

## Changes under consideration

Fixed retention periods, scheduled encrypted offsite backups and a verified erasure/restore process are proposed work. They are not current guarantees. Until those changes are implemented and verified, contact Russell Jones for the current handling of your request and avoid submitting data that requires a retention or erasure guarantee the preview cannot provide.
