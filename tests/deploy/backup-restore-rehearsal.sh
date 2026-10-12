#!/usr/bin/env bash
# Backup / restore rehearsal (PRD Phase 0 exit gate), runnable locally and in CI.
#
#   sudo MYSQL_DEFAULTS=/path/to/root.cnf bash tests/deploy/backup-restore-rehearsal.sh <source-db>
#
# Copies <source-db> into a throwaway database and a throwaway app folder,
# then: backup -> restore check -> damage the data -> restore -> compare.
# Also proves a wrong key and a damaged file are detected. Leaves nothing behind.
set -euo pipefail

SRC_DB="${1:?usage: backup-restore-rehearsal.sh <source-database>}"
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d /var/tmp/dml-rehearsal.XXXXXX)"
DB=dml_rehearsal_$$
export PATH="$HERE/deploy/server:$PATH"
M=(mysql); [ -n "${MYSQL_DEFAULTS:-}" ] && M=(mysql --defaults-extra-file="$MYSQL_DEFAULTS")
D=(mysqldump); [ -n "${MYSQL_DEFAULTS:-}" ] && D=(mysqldump --defaults-extra-file="$MYSQL_DEFAULTS")
cleanup() { "${M[@]}" -e "DROP DATABASE IF EXISTS \`$DB\`; DROP DATABASE IF EXISTS \`${DB}_restore_check\`" || true; rm -rf "$WORK"; }
trap cleanup EXIT
ok() { echo "  ✓ $*"; }
fail() { echo "  ✕ $*" >&2; exit 1; }
counts() { "${M[@]}" -N -B "$DB" -e "SELECT CONCAT((SELECT COUNT(*) FROM users), '/', (SELECT COUNT(*) FROM courses), '/', (SELECT COUNT(*) FROM files))"; }

chmod 755 "$HERE/deploy/server/dml-backup" "$HERE/deploy/server/dml-restore"
APP="$WORK/app"
mkdir -p "$APP/shared/storage/uploads/1/2026/10" "$APP/releases/rehearsal" "$WORK/backups" "$WORK/remote"
ln -s releases/rehearsal "$APP/current"
echo "APP_KEY=rehearsal-key" > "$APP/shared/.env"
printf 'hello\n' > "$APP/shared/storage/uploads/1/2026/10/0123456789abcdef0123456789abcdef"
"${M[@]}" -e "CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
"${D[@]}" --single-transaction --no-tablespaces "$SRC_DB" | "${M[@]}" "$DB"
(umask 077; openssl rand -base64 48 > "$WORK/key")
cat > "$WORK/backup.conf" <<CONF
APP_ROOT=$APP
DB_NAME=$DB
BACKUP_DIR=$WORK/backups
KEY_FILE=$WORK/key
REMOTE=$WORK/remote/
MYSQL_DEFAULTS=${MYSQL_DEFAULTS:-}
APP_USER=root
APP_GROUP=$(id -gn)
CONF
export DML_BACKUP_CONF="$WORK/backup.conf"

echo "Rehearsal against a copy of $SRC_DB"
before="$(counts)"
dml-backup > /dev/null && [ "$(find "$WORK/remote" -name '*.gpg' | wc -l)" = 1 ] || fail "backup made and copied off the server"
ok "backup made and copied off the server"
grep -q '"last_result":"ok"' "$APP/shared/storage/backup-status.json" || fail "status file says ok"
ok "status file says ok"
! grep -q "rehearsal-key" "$WORK"/backups/*.gpg || fail "backup is encrypted (secret not readable in the file)"
ok "backup is encrypted (secret not readable in the file)"

dml-restore verify latest > /dev/null || fail "restore check passed"
ok "restore check passed"
grep -q '"result":"ok"' "$APP/shared/storage/restore-check.json" || fail "restore-check file says ok"
ok "restore-check file says ok"
[ "$("${M[@]}" -N -e "SHOW DATABASES LIKE '${DB}_restore_check'")" = "" ] || fail "scratch database removed"
ok "scratch database removed"

cp "$WORK/key" "$WORK/key.good"; openssl rand -base64 48 > "$WORK/key"
if dml-restore verify latest > /dev/null 2>&1; then fail "a wrong key was not detected"; fi
grep -q '"result":"failed"' "$APP/shared/storage/restore-check.json" || fail "wrong key detected and reported"
ok "wrong key detected and reported"
cp "$WORK/key.good" "$WORK/key"

file="$(ls -1t "$WORK"/backups/*.gpg | head -1)"; cp "$file" "$WORK/good.gpg"
printf 'X' | dd of="$file" bs=1 seek=4000 conv=notrunc 2>/dev/null
if dml-restore verify latest > /dev/null 2>&1; then fail "a damaged backup was not detected"; fi
ok "damaged backup detected"
cp "$WORK/good.gpg" "$file"

"${M[@]}" "$DB" -e "SET FOREIGN_KEY_CHECKS = 0; DELETE FROM users; DELETE FROM courses; DELETE FROM files"
rm -rf "$APP/shared/storage/uploads"
[ "$(counts)" = "0/0/0" ] || fail "disaster simulated: data and uploads gone"
ok "disaster simulated: data and uploads gone"

DML_RESTORE_YES="$DB" dml-restore apply "$(basename "$file")" > /dev/null
[ "$(counts)" = "$before" ] || fail "restored: users/courses/files back to $before"
ok "restored: users/courses/files back to $before"
[ -f "$APP/shared/storage/uploads/1/2026/10/0123456789abcdef0123456789abcdef" ] || fail "uploaded files back"
ok "uploaded files back"
[ "$(ls "$WORK"/backups/*.gpg | wc -l)" -ge 2 ] || fail "the state before the restore was backed up first"
ok "the state before the restore was backed up first"
echo "Rehearsal passed."
