# Security policy

Please report vulnerabilities privately through GitHub Security Advisories for this repository. Do not open a public issue with exploit details or personal data.

## Required properties

- Organization ownership is enforced by both the control plane and Go data plane.
- Privileged service credentials never reach a browser.
- Cross-service assertions are short-lived, audience-bound, organization-bound, scoped, replay-resistant, and revocable.
- Logs contain correlation identifiers but no passwords, tokens, assertion material, form answers, or unnecessary personal data.
- The control plane never connects directly to the data-plane database.

Run `composer audit` and `composer check` before release. Security-sensitive dependency advisories, authentication failures, tenant-isolation failures, and secret exposure block release.
