# Site contract

This application is governed by `.waaseyaa/site.yaml`.

Before changing application behavior:

1. Read the capability manifest and its active, planned, and excluded decisions.
2. Use the selected first-party Waaseyaa recipes and extension points.
3. Run `tests/Architecture/SiteContractTest.php`.
4. Run the strict site diagnostics.
5. Run `bin/maintenance/site-verify` without network access.

Generated files are owned by `.waaseyaa/generated.json`. Regeneration refuses edits outside the extension region below.

<!-- waaseyaa:extension:start local-guidance -->
<!-- waaseyaa:extension:end local-guidance -->