# Backup and restore guide

What is protected, how, and exactly what to type when something goes wrong.

## What a backup contains

One encrypted file per night, `/var/backups/dml-lms/dml-lms-<UTC time>.tar.gpg`, holding:

| Part | What | Why |
|---|---|---|
| `database.sql.gz` | The whole `dml_lms` database (consistent snapshot, no site downtime) | Everything people did: accounts, courses, progress, submissions, certificates |
| `uploads.tar.gz` | `shared/storage/uploads/` | Lesson files and assignment files are not in the database |
| `env` | `shared/.env` | Holds `APP_KEY`; without it two-step sign-in secrets cannot be read and everyone must set up two-step again |
| `manifest.json` | Release, migrations, row counts, checksums of the parts | Lets the restore test prove the backup is complete |

Encryption: AES-256 (gpg symmetric) with the key in `/etc/dml-lms/backup.key`. **Keep a copy of that key off the server** (a password manager). A backup without its key is useless; a key on the same server as the backups protects nothing if the server is lost.

Not included, because they can be rebuilt: the application code (it is in Git and in each release tarball), PHP sessions, logs.

## Schedule and targets

| | Default | Where to change |
|---|---|---|
| Backup | Every night at about 02:15 (server time) | `dml-backup.timer` |
| Kept on the server | 14 days | `KEEP_DAYS` in `/etc/dml-lms/backup.conf` |
| Copy off the server | After every backup, when `REMOTE` is set | `REMOTE`, `REMOTE_SSH_KEY` |
| Restore test | Every Sunday at about 04:30 | `dml-restore-check.timer` |

**Proposed recovery objectives** (confirm with the service owner before public launch, PRD §13.2):

* **RPO** (most data that may be lost): 24 hours: the nightly backup. Before risky work, take an extra backup (`dml-backup`).
* **RTO** (time to be working again after losing the database or files): 4 hours on the same server; 1 working day on a new server (install from the latest release, then restore). The weekly restore test reports how long a restore of the current data takes.

The **System** page (platform administrators) shows the last good backup, whether it was copied off the server, and the last restore test, and turns red when a backup is more than 26 hours old or the restore test failed.

## Everyday commands (as root)

```bash
dml-backup                      # make a backup now (also copies it off the server)
dml-backup --no-offsite         # local only, e.g. just before a risky change
dml-restore list                # what is available
dml-restore verify latest       # prove the newest backup restores (safe: touches nothing live)
systemctl list-timers 'dml-*'   # when the next backup and restore test run
journalctl -u dml-backup -n 50  # what the last backup said
```

## Copying backups off the server

On a second machine (another VPS, a NAS, an office server) create a user that only receives files:

```bash
# on the backup machine
useradd -m backup && mkdir -p /srv/backups/dml-lms && chown backup: /srv/backups/dml-lms
```

On the LMS server:

```bash
ssh-keygen -t ed25519 -N '' -f /root/.ssh/dml_backup_ed25519
ssh-copy-id -i /root/.ssh/dml_backup_ed25519.pub backup@BACKUP-HOST
cat >> /etc/dml-lms/backup.conf <<'EOF'
REMOTE=backup@BACKUP-HOST:/srv/backups/dml-lms/
REMOTE_SSH_KEY=/root/.ssh/dml_backup_ed25519
EOF
dml-backup        # should end with "off-site: copied to …"
```

Old copies are **not** deleted on the remote side by this server, so an attacker who takes over the LMS server cannot erase them. Prune them on the backup machine, for example with a daily cron job there: `find /srv/backups/dml-lms -name 'dml-lms-*' -mtime +90 -delete`.

## Restoring

### A file or a few records went missing

Restore the backup into a separate copy and take what you need, leaving the live site alone:

```bash
dml-restore verify dml-lms-20261012T021503Z.tar.gpg   # proves it is good
```

For a full side copy, decrypt by hand and load the dump into a new database, then copy rows or files across:

```bash
mkdir -m 700 /root/restore && cd /root/restore
gpg --batch --pinentry-mode loopback --passphrase-file /etc/dml-lms/backup.key -d /var/backups/dml-lms/FILE.tar.gpg | tar -xf -
mysql -e "CREATE DATABASE dml_lms_copy"
gunzip < dml-lms-*/database.sql.gz | mysql dml_lms_copy
tar -tzf dml-lms-*/uploads.tar.gz | head        # files are under uploads/<school>/<year>/<month>/
```

Delete `/root/restore` and `DROP DATABASE dml_lms_copy` when finished: they contain personal data.

### The database or the uploads are lost or damaged

```bash
dml-restore list
dml-restore apply dml-lms-20261012T021503Z.tar.gpg
```

It shows when the backup was made, asks you to type the database name, **first backs up the current state**, then replaces the database and the uploads folder (the old folder is kept as `uploads.before-restore-<time>`). Add `--with-env` only if `shared/.env` was also lost.

If the backup is from an older release than the one running, finish with:

```bash
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console migrate
```

### The whole server is gone

1. New AlmaLinux server: run the installer with the latest release tarball (deployment guide → First install).
2. Put the backup key back: `install -m 600 /dev/stdin /etc/dml-lms/backup.key` and paste it.
3. Copy the latest backup from the backup machine into `/var/backups/dml-lms/`.
4. `dml-restore apply <file> --with-env` (restores `.env`, so `APP_KEY` and secrets match the data).
5. `systemctl reload php-fpm`, sign in, check the System page.
6. Point DNS at the new server if its address changed.

## Rehearsal

`tests/deploy/backup-restore-rehearsal.sh` runs the whole cycle against a copy of a database: backup and off-site copy, restore test, wrong key detected, damaged file detected, data and uploads deleted, restore, everything back. CI runs it on every push against MySQL 8.4; it was also run against MySQL 8.0.46 and MariaDB 10.11.

On the server, the weekly restore test repeats the important half of this against real data. Do a full rehearsal (on a spare server or VM, never on production) before public launch and after any change to the backup scripts, and write down how long the restore took: that is the measured RTO.
