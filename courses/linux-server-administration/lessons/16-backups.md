Nobody is judged on their backups. They are judged on their **restores**. A backup you have never restored is a hope, not a backup.

## Decide what matters

On a typical web server you need:

| What | Where it usually lives | How to capture it |
|---|---|---|
| Website or application files | `/var/www`, `/srv`, `/opt` | copy files |
| Uploaded files | inside the app's folder | copy files |
| Databases | `/var/lib/mysql` (never copy this live) | a **dump**, e.g. `mysqldump` |
| Configuration | `/etc` | copy files |
| Certificates | `/etc/letsencrypt` | copy files |

You do not need to back up the operating system itself if you can reinstall it and restore the pieces above. Write down how you built the server, or better, script it.

## The 3-2-1 rule

Keep **3** copies of your data, on **2** different kinds of storage, with **1** copy somewhere else (another server, another provider, another building). A backup on the same disk as the data protects against mistakes, but not against a failed disk or a hacked server.

## Making an archive with tar

```bash
sudo mkdir -p /backup
STAMP=$(date +%F-%H%M)
sudo tar -czf /backup/site-$STAMP.tar.gz -C /var/www site
sudo tar -czf /backup/etc-$STAMP.tar.gz /etc
tar -tzf /backup/site-$STAMP.tar.gz | head     # list what is inside
```

`-c` create, `-z` compress, `-f` file name, `-C` change directory first (so paths inside the archive are short), `-t` list.

## Dumping a database

```bash
sudo sh -c "mysqldump --single-transaction --routines --triggers schooldb | gzip > /backup/schooldb-$STAMP.sql.gz"
```

`--single-transaction` takes a consistent snapshot of InnoDB tables without locking the site. The whole pipeline is wrapped in `sudo sh -c "…"` because a `>` redirect is performed by **your** shell, not by sudo, and your user cannot write to `/backup`.

Restoring into a fresh test database:

```bash
sudo mysql -e "CREATE DATABASE schooldb_restore_test"
gunzip < /backup/schooldb-2026-10-12-0200.sql.gz | sudo mysql schooldb_restore_test
```

## Copying off the server with rsync

```bash
rsync -az --delete /backup/ backupuser@203.0.113.50:/backups/web1/
```

`-a` keeps permissions and times, `-z` compresses in transit, and `--delete` removes files at the destination that no longer exist at the source (so old archives you prune locally are pruned there too). rsync only sends what changed, so it works well on slow links. Use an SSH key dedicated to backups.

> **Tip:** Better still, let the backup server **pull** from the web server. Then an attacker who takes over the web server cannot delete the off-site copies.

For bigger setups, tools such as **restic** or **borg** add encryption, deduplication and retention policies. The principles are the same.

## Keep a sensible number

```bash
find /backup -name '*.tar.gz' -mtime +14 -delete    # remove archives older than 14 days
```

## Test the restore

At least once a month, and after any change to how backups are made:

1. Copy a backup to a different place (a test VM is ideal).
2. Unpack the files and import the database dump.
3. Check the site actually works and the newest data is there.
4. Write down how long it took. That is your real recovery time.

> **Important:** Backups contain everything sensitive about your users. Restrict them (`chmod 600`, owned by root), and encrypt copies that leave the server.

## Try it

Write a script `/usr/local/bin/backup-site.sh` that archives `/var/www` and `/etc`, dumps your database if you have one, deletes local archives older than 7 days, and copies the folder to another machine with rsync. Run it once by hand and then restore the archive into `/tmp/restore-test` to prove it works.
