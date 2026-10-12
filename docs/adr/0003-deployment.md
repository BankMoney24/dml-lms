# ADR 0003 — Immutable artifacts, restricted deploy gate, symlink switch

**Status:** accepted · 2026-10-12

## Context
One dedicated server already hosts other domains. The PRD forbids root deploys and `git pull` + arbitrary shell on the server (§16.4).

## Decision
* GitHub Actions builds one tarball per release (`REVISION` = `YYYYMMDD-HHMMSS-<sha12>`), records its sha256, and deploys the same bytes to staging then, after approval in the protected `production` environment, to production.
* The deploy SSH key is bound by `authorized_keys` to `dml-deploy-gate`, which accepts only `upload <id>`, `deploy <id> <sha256>`, `rollback`, `status` with strict argument patterns. No shell, no forwarding.
* `dml-deploy` verifies the checksum and release identity, extracts into `releases/<id>`, links `shared/.env` and `shared/storage`, backs up the database if migrations are pending, migrates, switches `current` with an atomic rename, and health-checks `/health/ready` for the new release id. On failure it switches back.
* Rollback follows a history of releases that actually went live, never "newest directory".
* Third-party actions are pinned to full commit SHAs; workflow tokens are read-only except the one-off lockfile helper.

## Rehearsal findings (fixed before first use)
* PHP's realpath cache kept serving the old release for minutes on symlinked docroots (Apache/cPanel). The front controller now clears it and resolves the live release per request.
* A first deploy mis-detected "previous release"; manual rollback chose the newest (broken) release. Both fixed; the rehearsal script exercises them.

## Consequences
Rollback switches code only. Migrations therefore must stay backward compatible for one release (expand/contract).
