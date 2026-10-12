# ADR 0001 — Shared database, tenant column, composite foreign keys

**Status:** accepted · 2026-10-12

## Context
The PRD requires strict tenant separation (TEN-002/003/005) and, later, a stronger isolation tier for enterprise tenants (TEN-007). The first deployment is one dedicated server with one MySQL instance.

## Decision
* One schema; every tenant-owned table has `tenant_id`.
* The tenant is resolved only from a **verified** `tenant_domains.host`.
* Repositories take the tenant id as an explicit argument (no ambient global scope), so the scope is visible at every call site and in code review.
* Child tables use composite foreign keys `(tenant_id, parent_id) → parent(tenant_id, id)` so the database rejects cross-tenant references.
* Users are global; access is via `memberships (tenant_id, user_id, role)`.

## Consequences
* A forgotten `tenant_id` filter is caught by tests and, for writes, by the database.
* Moving one tenant to a dedicated database later means pointing its connection elsewhere; domain logic is unchanged because it already passes tenant ids explicitly.
* One role per membership for now. Scoped assignments (e.g. educator of one course) will be a `role_assignments` table when needed.
