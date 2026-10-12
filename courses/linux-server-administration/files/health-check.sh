#!/usr/bin/env bash
# health-check.sh: prints problems and exits 1; prints nothing when all is well,
# so cron only sends mail when something is wrong.
# From the course "Linux Server Administration".
#   sudo install -m 755 health-check.sh /usr/local/bin/health-check.sh
#   # /etc/cron.d/health-check
#   MAILTO=you@example.org
#   */15 * * * * root /usr/local/bin/health-check.sh
set -u

URL=https://school.example.org/
BACKUP_DIR=/backup
DISK_LIMIT=80        # percent
CERT_DAYS=14         # warn when a certificate has fewer days left

problems=()

for mount in / /var; do
    [[ -d $mount ]] || continue
    used=$(df --output=pcent "$mount" | tail -1 | tr -dc '0-9')
    (( used > DISK_LIMIT )) && problems+=("Disk $mount is ${used}% full")
done

failed=$(systemctl --failed --no-legend --plain | awk '{print $1}' | tr '\n' ' ')
[[ -n "${failed// }" ]] && problems+=("Failed services: $failed")

code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$URL")
[[ "$code" != "200" ]] && problems+=("$URL answered HTTP $code")

host=$(echo "$URL" | awk -F/ '{print $3}')
end=$(echo | openssl s_client -connect "$host:443" -servername "$host" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
if [[ -n "$end" ]]; then
    days=$(( ( $(date -d "$end" +%s) - $(date +%s) ) / 86400 ))
    (( days < CERT_DAYS )) && problems+=("Certificate for $host expires in $days days")
fi

newest=$(find "$BACKUP_DIR" -name '*.tar.gz' -mmin -1500 2>/dev/null | head -1)
[[ -z "$newest" ]] && problems+=("No backup in $BACKUP_DIR in the last 25 hours")

if (( ${#problems[@]} > 0 )); then
    printf '%s: %s\n' "$(hostname)" "${problems[@]}"
    exit 1
fi
