#!/usr/bin/env bash
# Turns on virus scanning of uploads for the DML Learning Platform (ADR 0008, threat model).
#
#   bash /var/www/dml-lms/current/deploy/server/setup-clamav.sh
#
# Safe to run again. It chooses, in this order:
#   1. a ClamAV daemon already answering on 127.0.0.1:3310: use it as it is;
#   2. iRedMail's own ClamAV (clamd@amavisd): let it also listen on 127.0.0.1:3310,
#      so the server keeps ONE copy of the virus database in memory (no extra ~1 GB);
#   3. otherwise install ClamAV from EPEL as clamd@scan, only if enough memory is free.
# Then it sets UPLOAD_SCANNER=clamd in shared/.env, reloads PHP-FPM gracefully and
# checks the scanner with the harmless EICAR test text.
#
# It never touches Apache, other sites' configuration, DNS or mail routing. Each config
# file it changes is copied first to <file>.before-dml-<date>.
set -euo pipefail

ENV_FILE=${ENV_FILE:-/var/www/dml-lms/shared/.env}
APP=${APP:-/var/www/dml-lms/current}
PORT=3310
MEDIA_LIMIT=${MEDIA_LIMIT:-210M}
STAMP=$(date +%Y%m%d-%H%M%S)

say() { printf '\n== %s\n' "$*"; }
die() { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || die "run as root"
[ -f "$ENV_FILE" ] || die "$ENV_FILE not found"

ping_clamd() {
  # PING over TCP with bash only (no extra tools needed).
  { exec 3<>"/dev/tcp/127.0.0.1/$PORT"; } 2>/dev/null || return 1
  printf 'zPING\0' >&3
  local reply
  reply=$(timeout 3 head -c 16 <&3 | tr -d '\0' || true)
  exec 3>&- 3<&- || true
  [ "$reply" = "PONG" ]
}

wait_for_clamd() {
  say "Waiting for ClamAV to load its virus database (can take up to 2 minutes)"
  for _ in $(seq 1 60); do
    if ping_clamd; then echo "ClamAV answers on 127.0.0.1:$PORT"; return 0; fi
    sleep 2
  done
  return 1
}

# Sets "Key value" in a clamd config: replaces an existing or commented line, or appends.
set_option() {
  local file=$1 key=$2 value=$3
  if grep -qE "^#?[[:space:]]*${key}[[:space:]]" "$file"; then
    sed -i -E "s|^#?[[:space:]]*${key}[[:space:]].*|$key $value|" "$file"
  else
    printf '%s %s\n' "$key" "$value" >> "$file"
  fi
}

backup() { cp -p "$1" "$1.before-dml-$STAMP"; echo "Saved a copy: $1.before-dml-$STAMP"; }

say "Memory now"
free -m

if ping_clamd; then
  say "A ClamAV daemon already answers on 127.0.0.1:$PORT; using it"
elif systemctl list-unit-files 'clamd@*' 2>/dev/null | grep -q 'clamd@' && [ -f /etc/clamd.d/amavisd.conf ]; then
  CONF=/etc/clamd.d/amavisd.conf
  UNIT=clamd@amavisd
  say "Found iRedMail's ClamAV ($UNIT); letting it also listen on 127.0.0.1:$PORT"
  backup "$CONF"
  set_option "$CONF" TCPSocket "$PORT"
  set_option "$CONF" TCPAddr 127.0.0.1
  # Large enough for video and audio uploads; mail keeps its own smaller limits in amavisd.
  set_option "$CONF" StreamMaxLength "$MEDIA_LIMIT"
  set_option "$CONF" MaxFileSize "$MEDIA_LIMIT"
  set_option "$CONF" MaxScanSize 420M
  echo "Restarting $UNIT. Mail scanning pauses while it reloads; mail waits and is delivered after."
  systemctl restart "$UNIT"
  wait_for_clamd || { cp -p "$CONF.before-dml-$STAMP" "$CONF"; systemctl restart "$UNIT"; die "ClamAV did not answer; the old config was put back"; }
else
  AVAIL=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
  say "No ClamAV running. Available memory: ${AVAIL} MB"
  [ "$AVAIL" -ge 1400 ] || die "ClamAV needs about 1.2 GB of free memory and only ${AVAIL} MB is available. Free memory first (or ask for help); nothing was changed."
  rpm -q epel-release >/dev/null 2>&1 || dnf install -y epel-release
  dnf install -y clamav clamd clamav-update
  CONF=/etc/clamd.d/scan.conf
  backup "$CONF"
  sed -i 's/^Example/#Example/' "$CONF" /etc/freshclam.conf
  set_option "$CONF" TCPSocket "$PORT"
  set_option "$CONF" TCPAddr 127.0.0.1
  set_option "$CONF" StreamMaxLength "$MEDIA_LIMIT"
  set_option "$CONF" MaxFileSize "$MEDIA_LIMIT"
  set_option "$CONF" MaxScanSize 420M
  freshclam || echo "freshclam could not update now; the timer will try again"
  systemctl enable --now clamav-freshclam clamd@scan
  wait_for_clamd || die "ClamAV did not answer. See: journalctl -u clamd@scan --since -10min"
fi

say "Pointing the learning platform at ClamAV ($ENV_FILE)"
backup "$ENV_FILE"
for pair in "UPLOAD_SCANNER=clamd" "CLAMD_HOST=127.0.0.1" "CLAMD_PORT=$PORT"; do
  key=${pair%%=*}
  if grep -q "^$key=" "$ENV_FILE"; then sed -i "s|^$key=.*|$pair|" "$ENV_FILE"; else echo "$pair" >> "$ENV_FILE"; fi
done
grep -E '^(UPLOAD_SCANNER|CLAMD_HOST|CLAMD_PORT)=' "$ENV_FILE"

say "Reloading PHP-FPM gracefully (no dropped requests)"
php-fpm -t && systemctl reload php-fpm

say "Testing with the EICAR test text (harmless, made for testing virus scanners)"
if [ -x "$APP/bin/console" ] || [ -f "$APP/bin/console" ]; then
  sudo -u dmldeploy -H php "$APP/bin/console" files:scan-test
else
  echo "Skipped: $APP/bin/console not found"
fi

say "Done. School → System (platform administrators) now shows the scanner as working."
