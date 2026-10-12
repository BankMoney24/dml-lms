Backups, certificate renewals, clean-ups and reports should run on their own. Linux has two schedulers: the classic **cron** and **systemd timers**.

## cron

Each user has a crontab. Edit yours with:

```bash
crontab -e          # your own jobs
sudo crontab -e     # root's jobs
crontab -l          # list
```

A line has five time fields, then the command:

```text
# minute  hour  day-of-month  month  day-of-week   command
  30      2     *             *      *             /usr/local/bin/backup-site.sh
```

| Line | Runs |
|---|---|
| `*/5 * * * *` | every 5 minutes |
| `0 * * * *` | at the start of every hour |
| `30 2 * * *` | 02:30 every day |
| `0 6 * * 1-5` | 06:00 Monday to Friday |
| `0 3 1 * *` | 03:00 on the 1st of every month |

Times use the server's time zone (`timedatectl` shows it).

### System-wide jobs in /etc/cron.d

Files in `/etc/cron.d/` have an extra **user** field, which makes it clear what runs as whom:

```text
# /etc/cron.d/backup
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin
MAILTO=admin@example.org
30 2 * * * root /usr/local/bin/backup-site.sh >> /var/log/backup-site.log 2>&1
```

### Common cron mistakes

- **PATH:** cron runs with a minimal PATH. Use full paths to commands, or set `PATH=` at the top as above.
- **No output:** if you do not redirect output, cron tries to email it, and it is often lost. Send it to a log file.
- **Overlaps:** if a job can take longer than the gap between runs, two copies may run at once. Wrap it with `flock`:

```text
*/5 * * * * www-data flock -n /tmp/sync.lock /usr/local/bin/sync.sh
```

`-n` makes the second copy give up immediately instead of waiting.

## systemd timers

A timer is a pair of units: a **service** that does the work and a **timer** that says when. It costs one more file than cron but gives you logs in the journal, catching up on missed runs, and status at a glance.

`/etc/systemd/system/backup-site.service`:

```ini
[Unit]
Description=Back up website and configuration

[Service]
Type=oneshot
ExecStart=/usr/local/bin/backup-site.sh
Nice=10
```

`/etc/systemd/system/backup-site.timer`:

```ini
[Unit]
Description=Nightly website backup

[Timer]
OnCalendar=*-*-* 02:30:00
RandomizedDelaySec=15m
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now backup-site.timer
systemctl list-timers                      # next and last run of every timer
sudo systemctl start backup-site.service   # run it now, to test
sudo journalctl -u backup-site.service     # its output
```

`Persistent=true` means that if the server was off at 02:30, the job runs soon after it boots. `RandomizedDelaySec` spreads jobs so many servers do not all hit the backup host at once.

## Which should I use?

Both are fine. Use cron for quick, simple jobs and when a tool's documentation gives you a cron line. Use timers when you want journal logs, `Persistent=` catch-up, or the hardening options of a systemd service.

## Try it

Schedule your backup script from the previous lesson to run every night, using either cron or a timer. Prove when it will next run (`systemctl list-timers` or the time in your crontab) and show its log after a test run.
