#!/usr/bin/env bash
# Read-only server inspection (PRD §16.5). Changes nothing.
# Run as root on the server and share the output before deployment
# automation is finalised:   bash inspect.sh > inspect.txt 2>&1
# It prints no passwords or keys; review the output before sharing anyway.
set -u

section() { printf '\n===== %s =====\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }

section "OS"
cat /etc/os-release 2>/dev/null | grep -E '^(PRETTY_NAME|VERSION_ID)='
uname -r
uptime

section "Control panel"
[ -d /usr/local/cpanel ] && echo "cPanel/WHM: $(cat /usr/local/cpanel/version 2>/dev/null)"
[ -d /usr/local/psa ] && echo "Plesk detected"
[ -d /usr/local/directadmin ] && echo "DirectAdmin detected"
[ -d /usr/local/CyberCP ] && echo "CyberPanel detected"
have virtualmin && echo "Virtualmin detected"

section "Web servers"
have nginx && nginx -v 2>&1
have httpd && httpd -v 2>&1 | head -1
have apache2 && apache2 -v 2>&1 | head -1
[ -x /usr/local/lsws/bin/lshttpd ] && echo "LiteSpeed: $(/usr/local/lsws/bin/lshttpd -v 2>&1 | head -1)"
ss -ltnp 2>/dev/null | grep -E ':(80|443|8080|8443)\s' | awk '{print $4, $6}'

section "PHP versions"
have php && php -v | head -1
ls -d /opt/cpanel/ea-php*/root/usr/bin/php 2>/dev/null
ls -d /usr/bin/php[0-9]* /usr/bin/php[0-9].[0-9] 2>/dev/null
ls /etc/php/ 2>/dev/null
systemctl list-units --type=service --no-legend 2>/dev/null | grep -Ei 'php.*fpm' | awk '{print $1, $4}'

section "PHP extensions (default php)"
have php && php -m 2>/dev/null | grep -Ei '^(pdo_mysql|mbstring|intl|iconv|opcache|Zend OPcache|sodium|redis)$'

section "Database"
have mysql && mysql --version
have mariadb && mariadb --version
systemctl is-active mysqld mysql mariadb 2>/dev/null

section "Redis"
have redis-server && redis-server --version
systemctl is-active redis redis-server 2>/dev/null

section "Domains / virtual hosts"
if [ -f /etc/userdomains ]; then
  echo "(cPanel) domain: account"
  grep -v '^\*' /etc/userdomains
fi
for d in /etc/nginx/sites-enabled /etc/nginx/conf.d; do
  [ -d "$d" ] && grep -RhoE 'server_name\s+[^;]+' "$d" 2>/dev/null | sort -u
done
for d in /etc/httpd/conf.d /etc/apache2/sites-enabled; do
  [ -d "$d" ] && grep -RhoE '^\s*Server(Name|Alias)\s+.+' "$d" 2>/dev/null | sort -u
done

section "TLS"
have certbot && certbot --version 2>&1
ls /etc/letsencrypt/live 2>/dev/null
[ -d /var/cpanel/ssl ] && echo "cPanel AutoSSL present"

section "Disk and memory"
df -h / /home /var 2>/dev/null
free -h

section "Firewall"
have firewall-cmd && firewall-cmd --list-all 2>/dev/null | head -20
have ufw && ufw status 2>/dev/null
have csf && csf -v 2>/dev/null | head -1

section "SSH"
grep -Ei '^\s*(Port|PermitRootLogin|PasswordAuthentication|PubkeyAuthentication|AllowUsers)\b' /etc/ssh/sshd_config 2>/dev/null

section "Tools"
for t in git composer curl tar sha256sum flock mysqldump gzip; do
  printf '%-10s %s\n' "$t" "$(command -v $t || echo MISSING)"
done

section "Backups"
ls /backup 2>/dev/null | head
[ -f /var/cpanel/backups/config ] && grep -E '^(BACKUPENABLE|BACKUPDIR|BACKUPTYPE)' /var/cpanel/backups/config

echo
echo "Done. Nothing was changed."
