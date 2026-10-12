You cannot fix what you cannot see. A healthy server tells you about problems before your users do.

## Where logs are

| What | AlmaLinux | Ubuntu |
|---|---|---|
| Everything systemd collects | `journalctl` | `journalctl` |
| General system log | `/var/log/messages` | `/var/log/syslog` |
| Logins, sudo, SSH | `/var/log/secure` | `/var/log/auth.log` |
| Web server | `/var/log/nginx/`, `/var/log/httpd/` | `/var/log/nginx/`, `/var/log/apache2/` |
| Database | `journalctl -u mariadb` or `/var/log/mariadb/` | `/var/log/mysql/` |
| Package changes | `/var/log/dnf.log` | `/var/log/apt/history.log` |

## Log rotation

Logs grow forever unless they are **rotated**: renamed, compressed, and eventually deleted. `logrotate` does this daily using rules in `/etc/logrotate.d/`. Add one for your own application:

```text
/var/log/myapp/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0640 myapp myapp
}
```

Test it without waiting a week: `sudo logrotate -d /etc/logrotate.d/myapp` (dry run) and `sudo logrotate -f /etc/logrotate.d/myapp` (force).

The journal has its own size limit; set it in `/etc/systemd/journald.conf` with `SystemMaxUse=500M`, then `sudo systemctl restart systemd-journald`.

## What to watch

| Check | Warning sign | Command |
|---|---|---|
| Disk space | over 80% used | `df -h` |
| Memory | little *available*, swap growing | `free -h` |
| Load | above the number of cores for long periods | `uptime`, `nproc` |
| Services | anything failed | `systemctl --failed` |
| The website | not answering, or slow | `curl -sS -o /dev/null -w '%{http_code} %{time_total}\n' https://…` |
| Certificates | under 14 days left | `certbot certificates` |
| Backups | last one older than a day | `ls -lt /backup | head` |
| Security | bursts of failed logins | `journalctl -u sshd --since today | grep -c Failed` |

## A small health-check script

`/usr/local/bin/health-check.sh`:

```bash
#!/usr/bin/env bash
# Prints problems; prints nothing when all is well (so cron only emails on trouble).
set -u
problems=()

disk=$(df --output=pcent / | tail -1 | tr -dc '0-9')
(( disk > 80 )) && problems+=("Disk / is ${disk}% full")

failed=$(systemctl --failed --no-legend --plain | awk '{print $1}')
[[ -n "$failed" ]] && problems+=("Failed services: $failed")

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 https://school.example.org/)
[[ "$code" != "200" ]] && problems+=("Website answered HTTP $code")

newest=$(find /backup -name '*.tar.gz' -mmin -1500 | head -1)
[[ -z "$newest" ]] && problems+=("No backup in the last 25 hours")

if (( ${#problems[@]} > 0 )); then
    printf '%s\n' "${problems[@]}"
    exit 1
fi
```

```bash
sudo chmod 755 /usr/local/bin/health-check.sh
sudo /usr/local/bin/health-check.sh; echo "exit code $?"
```

Run it every 15 minutes from cron with `MAILTO=you@example.org` set (if the server can send email), or have it send a message to a chat webhook.

## Watching from the outside

A script on the server cannot tell you the server is **down**. Use an external uptime monitor as well: many services offer free plans that request your site every few minutes and alert you by email or phone when it stops answering. Monitor the real public address, over HTTPS.

## Try it

Install the health-check script, adapt the URL and backup path, and break each check on purpose: stop nginx, fill the disk with `fallocate -l 1G /tmp/big` (then delete it), and move your latest backup away. Confirm the script reports each one.
