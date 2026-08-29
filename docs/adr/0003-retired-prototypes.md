# ADR 0003: Retire the Laravel and Form.io prototype paths

Status: accepted, 2026-08-29

## Decision

The former Laravel website and the forked Form.io-oriented frontend are historical prototypes, not migration sources or runtime dependencies. They are not reused in the control plane.

The product direction is AI-first and schema-first: canonical JSON Schema and OpenAPI contracts live in the Go data plane, while Waaseyaa provides a required first-party human interface over the same contracts available to agents and third-party dashboards. Useful product language or visual ideas may be reimplemented deliberately, but no prototype database, authentication model, or domain library is imported.

This prevents a third authority from emerging and keeps tech debt visible in the active repositories and roadmap.
