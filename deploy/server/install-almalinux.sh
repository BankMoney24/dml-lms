#!/usr/bin/env bash
# One-time setup of the DML LMS on this server: AlmaLinux 9, Apache 2.4,
# PHP-FPM 8.3, MySQL 8, SELinux. Safe to re-run; it skips what already exists.
#
#   bash install-almalinux.sh /root/<release-id>.tar.gz [host] [port]
#
# host/port default to 66.29.156.116 and 8088 (temporary access until the
# domain's DNS is ready). It adds a separate site, PHP pool, database and
# users; it does not modify any other site's configuration.
set -euo pipefail

TARBALL="$(readlink -f "${1:?usage: install-almalinux.sh <release.tar.gz> [host] [port]}")"
HOST="${2:-66.29.156.116}"
PORT="${3:-8088}"
APP_ROOT=/var/www/dml-lms
DEPLOY_USER=dmldeploy
APP_USER=dmllms
DB_NAME=dml_lms
DB_USER=dml_lms
MYSQL_CMD="${MYSQL_CMD:-mysql}"   # if root needs a password: MYSQL_CMD="mysql -u root -p"
SRC="$(cd "$(dirname "$0")/../.." && pwd)"   # unpacked release (for config templates)

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

ID="$(basename "$TARBALL" .tar.gz)"
[[ "$ID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] || die "tarball name must be <release-id>.tar.gz"
[ "$(id -u)" = 0 ] || die "run as root"
for c in php apachectl php-fpm openssl curl flock sha256sum; do command -v "$c" >/dev/null || die "$c not found"; done
[[ "$PORT" =~ ^[0-9]+$ ]] || die "port must be a number"
if ss -ltn "sport = :$PORT" | grep -q LISTEN && ! grep -qs "Listen $PORT" /etc/httpd/conf.d/dml-lms.conf; then
  die "port $PORT is already in use by something else; pass another port as the third argument"
fi

step "Users ($DEPLOY_USER deploys, $APP_USER runs PHP)"
id "$APP_USER" >/dev/null 2>&1 || useradd --system --no-create-home --shell /sbin/nologin "$APP_USER"
id "$DEPLOY_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
usermod -aG "$APP_USER" "$DEPLOY_USER"
DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6)"

step "Directories under $APP_ROOT"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 "$APP_ROOT" "$APP_ROOT/releases" "$APP_ROOT/shared"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 700 "$APP_ROOT/incoming" "$APP_ROOT/shared/backups"
for d in "" /logs /sessions /cache; do
  install -d -o "$APP_USER" -g "$APP_USER" -m 2770 "$APP_ROOT/shared/storage$d"
done

step "SELinux labels"
if command -v selinuxenabled >/dev/null && selinuxenabled; then
  command -v semanage >/dev/null || dnf -y -q install policycoreutils-python-utils
  semanage fcontext -a -t httpd_sys_rw_content_t "$APP_ROOT/shared/storage(/.*)?" 2>/dev/null \
    || semanage fcontext -m -t httpd_sys_rw_content_t "$APP_ROOT/shared/storage(/.*)?"
  restorecon -R "$APP_ROOT"
  if ! semanage port -l | awk '$1=="http_port_t"' | grep -qw "$PORT"; then
    semanage port -a -t http_port_t -p tcp "$PORT" 2>/dev/null || semanage port -m -t http_port_t -p tcp "$PORT"
  fi
  echo "storage writable by PHP; port $PORT allowed for Apache"
else
  echo "SELinux not enforcing; skipped"
fi

step "Database and configuration"
if [ ! -f "$APP_ROOT/shared/.env" ]; then
  DB_PASS="Dm1-$(openssl rand -hex 20)-Lx"
  # Prefer the Unix socket: no TCP, and no SELinux boolean change needed for PHP to reach MySQL.
  DB_SOCKET=""
  for s in /var/lib/mysql/mysql.sock /run/mysqld/mysqld.sock; do [ -S "$s" ] && { DB_SOCKET="$s"; break; }; done
  if [ -z "$DB_SOCKET" ] && command -v setsebool >/dev/null && selinuxenabled; then
    setsebool -P httpd_can_network_connect_db on
  fi
  $MYSQL_CMD <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
SQL
  umask 077
  cat > "$APP_ROOT/shared/.env" <<ENV
APP_ENV=production
APP_DEBUG=false
APP_KEY=$(openssl rand -hex 32)
PLATFORM_HOSTS=
DB_SOCKET=$DB_SOCKET
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS
# Plain HTTP on the temporary port. Set to true once the domain has HTTPS.
SESSION_SECURE_COOKIE=false
SESSION_IDLE_SECONDS=7200
ENV
  printf '[client]\nuser=%s\npassword=%s\n%s\n' "$DB_USER" "$DB_PASS" "${DB_SOCKET:+socket=$DB_SOCKET}" > "$DEPLOY_HOME/.my.cnf"
  umask 022
  chown "$DEPLOY_USER:$APP_USER" "$APP_ROOT/shared/.env"; chmod 640 "$APP_ROOT/shared/.env"
  chown "$DEPLOY_USER:$DEPLOY_USER" "$DEPLOY_HOME/.my.cnf"; chmod 600 "$DEPLOY_HOME/.my.cnf"
  restorecon "$APP_ROOT/shared/.env" 2>/dev/null || true
  echo "database $DB_NAME and user $DB_USER created; secrets written to shared/.env"
else
  echo "shared/.env already exists; database left as is"
fi

step "Deploy scripts"
install -m 755 "$SRC/deploy/server/dml-deploy" "$SRC/deploy/server/dml-deploy-gate" /usr/local/bin/
cat > "$DEPLOY_HOME/.dml-deploy.conf" <<CONF
APP_ROOT=$APP_ROOT
HEALTH_HOST=$HOST
HEALTH_SCHEME=http
HEALTH_PORT=$PORT
PHP_BIN=$(command -v php)
KEEP_RELEASES=5
UMASK=022
DB_NAME=$DB_NAME
RELOAD_CMD=""
CONF
chown "$DEPLOY_USER:$DEPLOY_USER" "$DEPLOY_HOME/.dml-deploy.conf"; chmod 600 "$DEPLOY_HOME/.dml-deploy.conf"

step "PHP-FPM pool (dml-lms)"
install -m 644 "$SRC/deploy/php-fpm/dml-lms-almalinux.conf" /etc/php-fpm.d/dml-lms.conf
php-fpm -t
systemctl reload php-fpm
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -S /run/php-fpm/dml-lms.sock ] && break; sleep 0.5; done
[ -S /run/php-fpm/dml-lms.sock ] || die "PHP-FPM pool socket did not appear; see journalctl -u php-fpm"

step "Apache site on port $PORT"
sed -e "s/__PORT__/$PORT/g" -e "s/__HOST__/$HOST/g" "$SRC/deploy/apache/dml-lms-port.conf" > /etc/httpd/conf.d/dml-lms.conf
if ! apachectl configtest; then
  rm -f /etc/httpd/conf.d/dml-lms.conf
  die "Apache config test failed; dml-lms.conf removed, other sites untouched"
fi
systemctl reload httpd

if systemctl is-active --quiet firewalld; then
  firewall-cmd --quiet --permanent --add-port="$PORT/tcp" && firewall-cmd --quiet --reload
  echo "firewalld: port $PORT/tcp open"
fi

step "Release $ID"
install -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 600 "$TARBALL" "$APP_ROOT/incoming/$ID.tar.gz"
SUM="$(sha256sum "$TARBALL" | cut -d' ' -f1)"
sudo -u "$DEPLOY_USER" -H /usr/local/bin/dml-deploy activate "$ID" "$SUM"

step "Tenant for $HOST"
CONSOLE=(sudo -u "$DEPLOY_USER" -H php "$APP_ROOT/current/bin/console")
if "${CONSOLE[@]}" tenant:create dml "DML School of Technology" "$HOST" 2>/dev/null; then :; else
  echo "tenant 'dml' already exists"
fi

cat <<DONE

Done. Release $ID is live at  http://$HOST:$PORT

Create your owner account (prints a one-time password):
  sudo -u $DEPLOY_USER -H php $APP_ROOT/current/bin/console user:create dml you@example.com "Your Name" owner

Optional demo course (needs APP_ENV other than production, so run it this way):
  sudo -u $DEPLOY_USER -H env APP_ENV=staging php $APP_ROOT/current/bin/console seed:demo dml

Logs: /var/log/httpd/dml-lms-error.log   $APP_ROOT/shared/storage/logs/   $APP_ROOT/deploy.log
DONE
