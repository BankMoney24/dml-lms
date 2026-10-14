#!/usr/bin/env bash
# Puts a school on its own domain with HTTPS (ADR 0019). Run as root on the server,
# once DNS for the domain and www point here:
#
#   bash /var/www/dml-lms/current/deploy/server/setup-domain.sh dmlschooloftechnology.org you@example.com [tenant-slug]
#
# What it does, in order, stopping at the first problem:
#   1. checks the domain and www resolve to this server
#   2. backs up the Apache config and the app's .env to /root/dml-domain-backup-<time>.tgz
#   3. installs mod_ssl and certbot if missing, opens http/https in the firewall
#   4. adds the domain's Apache site and maps both names to the school
#   5. gets a Let's Encrypt certificate (auto-renewing) and turns on HTTP→HTTPS
#   6. makes the domain the school's canonical address: PUBLIC_SCHEME=https, CANONICAL_REDIRECT=true,
#      secure cookies, HSTS for a year (without subdomains)
#   7. checks the result from the server itself
# It is safe to run again. It does not touch DNS, email or the temporary :8088 site.
set -euo pipefail

DOMAIN="${1:-}"
EMAIL="${2:-}"
SLUG="${3:-dml}"
APP=/var/www/dml-lms
CURRENT="$APP/current"
ENV_FILE="$APP/shared/.env"
DEPLOY_CONF="$(getent passwd dmldeploy | cut -d: -f6)/.dml-deploy.conf"
CONSOLE=(sudo -u dmldeploy -H php "$CURRENT/bin/console")
TS="$(date +%Y%m%d-%H%M%S)"

say() { printf '\n== %s\n' "$*"; }
die() { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run this as root"
[[ "$DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "usage: setup-domain.sh <domain> <email> [tenant-slug]  (domain like dmlschooloftechnology.org, lower case, no https://)"
[[ "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "give an email address for certificate expiry notices"
[ -d "$CURRENT" ] && [ -f "$ENV_FILE" ] || die "$CURRENT or $ENV_FILE not found; is the app installed?"

say "1/7 DNS"
mine="$(ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | tr '\n' ' ')"
for name in "$DOMAIN" "www.$DOMAIN"; do
  ip="$(getent ahostsv4 "$name" | awk 'NR==1{print $1}')"
  [ -n "$ip" ] || die "$name does not resolve yet. Add an A record pointing to this server and wait for it to spread."
  case " $mine " in
    *" $ip "*) echo "$name -> $ip (this server)";;
    *) echo "$name -> $ip; this server's addresses: $mine"
       [ "${SKIP_DNS_CHECK:-}" = 1 ] || die "$name points elsewhere. If this server sits behind NAT and $ip is right, run again with SKIP_DNS_CHECK=1.";;
  esac
done

say "2/7 Backup"
backup="/root/dml-domain-backup-$TS.tgz"
tar -czf "$backup" /etc/httpd/conf.d "$ENV_FILE" 2>/dev/null
chmod 600 "$backup"
echo "Saved $backup (restore with: tar -xzf $backup -C / && systemctl reload httpd php-fpm)"

say "3/7 Packages and firewall"
rpm -q mod_ssl >/dev/null 2>&1 || dnf -y -q install mod_ssl
if ! command -v certbot >/dev/null 2>&1; then
  rpm -q epel-release >/dev/null 2>&1 || dnf -y -q install epel-release
  dnf -y -q install certbot python3-certbot-apache
fi
if systemctl is-active --quiet firewalld; then
  firewall-cmd --quiet --permanent --add-service=http --add-service=https
  firewall-cmd --quiet --reload
  echo "Firewall: http and https open"
fi
systemctl enable --now certbot-renew.timer >/dev/null 2>&1 || true
# Link-preview images are drawn with GD; without it the plain brand image is used.
if ! php -r 'exit(function_exists("imagettftext") ? 0 : 1);'; then
  if dnf -y -q install php-gd; then systemctl restart php-fpm; echo "Installed php-gd for link-preview images"
  else echo "Could not install php-gd; link previews will use the brand image"; fi
fi

say "4/7 Apache site and school mapping"
sed "s/__DOMAIN__/$DOMAIN/g" "$CURRENT/deploy/apache/dml-lms-domain.conf" > /etc/httpd/conf.d/dml-lms-domain.conf
apachectl configtest
systemctl reload httpd
"${CONSOLE[@]}" tenant:add-domain "$SLUG" "$DOMAIN"
"${CONSOLE[@]}" tenant:add-domain "$SLUG" "www.$DOMAIN"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -H "Host: $DOMAIN" http://127.0.0.1/health/ready || true)"
[ "$code" = 200 ] || die "http://$DOMAIN/health/ready answered $code from this server. Check /var/log/httpd/dml-lms-error.log."
echo "http://$DOMAIN serves the school"

say "5/7 HTTPS certificate"
certbot --apache --non-interactive --agree-tos -m "$EMAIL" -d "$DOMAIN" -d "www.$DOMAIN" --redirect --keep-until-expiring
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health/ready" || true)"
[ "$code" = 200 ] || die "https://$DOMAIN/health/ready answered $code. The certificate step may have failed; nothing in the app was switched yet."

say "6/7 Canonical address, secure cookies and HSTS"
set_env() {
  if grep -q "^$1=" "$ENV_FILE"; then sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"; else printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"; fi
}
"${CONSOLE[@]}" tenant:primary-domain "$SLUG" "$DOMAIN"
set_env PUBLIC_SCHEME https
set_env CANONICAL_REDIRECT true
set_env SESSION_SECURE_COOKIE true
grep -q '^HSTS_MAX_AGE=' "$ENV_FILE" || set_env HSTS_MAX_AGE 31536000
if [ -f "$DEPLOY_CONF" ]; then
  sed -i "s|^HEALTH_HOST=.*|HEALTH_HOST=$DOMAIN|; s|^HEALTH_SCHEME=.*|HEALTH_SCHEME=https|; s|^HEALTH_PORT=.*|HEALTH_PORT=443|" "$DEPLOY_CONF"
  echo "Deploy health check now uses https://$DOMAIN"
fi
systemctl reload php-fpm

say "7/7 Checks from this server"
check() { # url expected-status [expected-location]
  out="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 15 --resolve "$DOMAIN:80:127.0.0.1" --resolve "$DOMAIN:443:127.0.0.1" --resolve "www.$DOMAIN:80:127.0.0.1" --resolve "www.$DOMAIN:443:127.0.0.1" "$1" || true)"
  printf '%-48s %s\n' "$1" "$out"
  case "$out" in "$2"*) ;; *) echo "  expected $2${3:+ to $3}";; esac
}
check "http://$DOMAIN/" 301 "https://$DOMAIN/"
check "https://www.$DOMAIN/courses" 301 "https://$DOMAIN/courses"
check "https://$DOMAIN/" 200
check "https://$DOMAIN/robots.txt" 200
check "https://$DOMAIN/sitemap.xml" 200
curl -sI --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/" | grep -i '^strict-transport-security' || echo "  (no HSTS header seen)"

cat <<NEXT

Done. https://$DOMAIN is the school's address.

Next:
  - Open https://$DOMAIN in a browser and sign in again (sessions do not carry over from the old address).
  - Visitors to the old http://<ip>:8088 address are sent to https://$DOMAIN. When you no longer need it:
      rm /etc/httpd/conf.d/dml-lms.conf && apachectl configtest && systemctl reload httpd
      firewall-cmd --permanent --remove-port=8088/tcp && firewall-cmd --reload
  - In Google Search Console and Bing Webmaster Tools, add https://$DOMAIN, verify it (DNS record, or
    School → Website in the app), and submit https://$DOMAIN/sitemap.xml.
  - Certificates renew automatically (certbot-renew.timer). Test with: certbot renew --dry-run
Undo (back to the old address only):
  tar -xzf $backup -C / && rm -f /etc/httpd/conf.d/dml-lms-domain*.conf && systemctl reload httpd php-fpm
  ${CONSOLE[*]} tenant:primary-domain $SLUG <the old address, e.g. the server IP>
NEXT
