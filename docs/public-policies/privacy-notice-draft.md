# GoFormX privacy notice

Draft for review, September 30, 2026. This notice describes the current developer preview. It has not been published or adopted as a new service commitment.

## Operator and contact

GoFormX is operated by Russell Jones. Contact [jonesrussell42@gmail.com](mailto:jonesrussell42@gmail.com) about privacy, access, corrections or deletion. Include the relevant site or form and enough information to locate your request; do not send passwords, access tokens or unnecessary sensitive information.

## What the service handles

GoFormX stores account and workspace information needed to sign in and authorize access, including names, email addresses, password hashes, organization memberships and authentication/security events. It stores form definitions, allowed site origins, published schema versions and the values people submit to a form. Accepted submissions also have identifiers, timestamps, site/form associations, request identifiers and retry keys.

The fields requested by a form are chosen by its owner. For questions about why a website asks for a particular field or how its owner uses your message, contact that website's owner. GoFormX processes the submitted fields to validate, store and make them available through the authorized inbox and API.

Operational and security records include token lifecycle metadata, management/export audit events and, where configured, webhook destination and delivery information. Infrastructure providers may process request information such as IP addresses and browser/request details to deliver and protect the service. We have not established a single verified retention period covering all infrastructure-provider records.

## Who can access information

The service checks organization membership and operation-specific permissions. Authorized workspace owners and administrators can read submissions. Form-definition access does not itself grant submission-reading access. External clients need a separately granted `submissions:read` scope to read submission content.

An AI assistant is not required for collection. The supported experimental Codex setup manages form definitions; it does not request submission-reading permission by default. This notice does not authorize sending private submissions to a model.

If a workspace configures a webhook, accepted submission data can be sent to that chosen receiver. Deleting or pausing its endpoint does not erase already queued delivery snapshots or copies already received elsewhere. The receiver controls its own copies.

The current hosting arrangement uses DigitalOcean infrastructure and Cloudflare for the public edge/DNS. Messages sent to the contact email are handled by the email provider. The service does not presently provide a verified data-residency commitment or complete public processor-retention schedule.

## Cookies and the public website

Account sign-in uses session and request-protection cookies. The proposed public website adds no analytics or advertising scripts and makes no remote font requests. Infrastructure security cookies or processing are separate from the application's own scripts. This statement is not a claim that the hosting edge collects no request information.

## Retention and deletion

There is currently no configured automatic age-based expiry for accepted submissions, form definitions or delivery history. Do not assume a message disappears after a fixed period. Token expiry or revocation removes credential access; it does not erase submissions, token lifecycle records or audits.

There is no self-service submission-deletion endpoint in the current public API. Contact Russell Jones to request access, correction or deletion. Requests need identity and authorization checks; the current service has no implemented guaranteed completion time or complete automated erasure workflow.

The account-deletion path refuses deletion while an account is the sole active owner of a workspace. Where deletion is allowed, it revokes memberships and deletes the account from the account store. It does not automatically erase the separate form/submission store, retained audits, backups or other recipients' copies. Some audit records are deliberately append-only and persist independently of their targets.

The [data retention and backup notice](data-retention-and-backup-draft.md) explains the current backup and recovery limits. We cannot currently promise deletion from every backup or prevention of deleted data returning during a restore.

## Preview limits and changes

Email notification delivery and emailed account recovery are not qualified. Use the authorized dashboard to check received submissions. An accepted API response is not proof of email or webhook delivery.

This draft does not claim regulatory certification, guaranteed delivery, guaranteed availability or a fixed retention/deletion service level. Any future retention schedule or stronger deletion promise must be implemented and verified before the public notice states it as current behavior.
