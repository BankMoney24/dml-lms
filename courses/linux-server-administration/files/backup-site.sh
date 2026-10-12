#!/usr/bin/env bash
# backup-site.sh: nightly backup of website files, /etc and one database,
# kept for KEEP_DAYS days and copied to another machine.
# From the course "Linux Server Administration". Install:
#   sudo install -m 750 backup-site.sh /usr/local/bin/backup-site.sh
# Edit the settings below, run it once by hand, then TEST A RESTORE.
set -euo pipefail

SITE_DIR=/var/www/school.example.org       # website files
DB_NAME=schooldb                           # leave empty if there is no database
BACKUP_DIR=/backup
KEEP_DAYS=7
REMOTE=backupuser@203.0.113.50:/backups/web1/   # leave empty to skip the off-site copy

umask 077                                  # backups contain secrets: root only
STAMP=$(date +%F-%H%M)
mkdir -p "$BACKUP_DIR"

echo "$(date -Is) backup starting"

tar -czf "$BACKUP_DIR/site-$STAMP.tar.gz" -C "$(dirname "$SITE_DIR")" "$(basename "$SITE_DIR")"
tar -czf "$BACKUP_DIR/etc-$STAMP.tar.gz" /etc 2>/dev/null

if [[ -n "$DB_NAME" ]]; then
    mysqldump --single-transaction --routines --triggers "$DB_NAME" | gzip > "$BACKUP_DIR/db-$DB_NAME-$STAMP.sql.gz"
    # A dump that is suspiciously small usually means something went wrong.
    if [[ $(stat -c %s "$BACKUP_DIR/db-$DB_NAME-$STAMP.sql.gz") -lt 1024 ]]; then
        echo "WARNING: database dump is under 1 KB" >&2
    fi
fi

# Check the archives can be read back before trusting them.
for f in "$BACKUP_DIR"/*-"$STAMP".tar.gz; do
    tar -tzf "$f" > /dev/null
done

find "$BACKUP_DIR" -type f \( -name '*.tar.gz' -o -name '*.sql.gz' \) -mtime +"$KEEP_DAYS" -delete

if [[ -n "$REMOTE" ]]; then
    rsync -az --delete "$BACKUP_DIR/" "$REMOTE"
fi

echo "$(date -Is) backup finished: $(du -sh "$BACKUP_DIR" | cut -f1) in $BACKUP_DIR"
