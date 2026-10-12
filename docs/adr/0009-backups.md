# ADR 0009: Backups and restore testing

Date: 2026-10-12 · Status: accepted

## Context

PRD §13.2 and the Phase 0 exit gate require encrypted backups copied off the production host and proven by scheduled restoration. The platform runs on one shared AlmaLinux server with MySQL 8.0; uploads live on disk, not in the database; `APP_KEY` in `shared/.env` encrypts two-step secrets.

## Decision

* **Two shell tools run by root through systemd timers**, not PHP: the PHP pool cannot run programs and must not hold database-root or key access. `dml-backup` nightly; `dml-restore verify latest` weekly.
* **One self-describing file per backup**: database dump (`--single-transaction`), uploads, `.env`, and a manifest with checksums, migrations and row counts; tarred and encrypted with gpg symmetric AES-256 using a key file in `/etc/dml-lms/` (root only). gpg is on every AlmaLinux install; a throwaway `GNUPGHOME` keeps root's keyring untouched.
* **Off-site by push with rsync over SSH**, never deleting on the remote side, so a compromised server cannot erase the history. Remote pruning happens on the backup host.
* **A restore test that actually restores**: decrypt, verify every checksum, load into a scratch database, compare row counts and migrations with the manifest, unpack uploads and count files, drop the scratch database. A wrong key, a damaged file or a short dump fails the test.
* **Status files** (`backup-status.json`, `restore-check.json`) written into `shared/storage` (group-readable by the app) drive the System page; failure keeps the last success visible.
* **`dml-restore apply`** asks for the database name, backs up the current state first, keeps the previous uploads folder, and restores `.env` only on request.
* The rehearsal script runs in CI on every push.

## Consequences

* Losing the key means losing every backup: the installer prints it once and the guide insists on an off-server copy.
* Backups are full, not incremental; fine at current sizes. If uploads grow large, move uploads to an incremental tool (restic/borg) and keep the database dump nightly.
* RPO 24 h and RTO 4 h are proposed, to be confirmed with the service owner.
