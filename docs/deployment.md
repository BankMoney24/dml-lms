# Deployment runbook

How code gets from GitHub to the dedicated server (66.29.156.116) for **dmlschooloftechnology**, without touching the other sites hosted there.

```
git push / tag v* ──► GitHub Actions ──► build ONE artifact (sha256 recorded)
                                          │
                                          ├─► staging     (automatic)
                                          └─► production  (waits for your approval)
                                                │  ssh, restricted key
                                                ▼
                                   dml-deploy-gate on the server
                                   upload → verify checksum → backup DB →
                                   migrate → switch "current" symlink →
                                   health check → auto-rollback on failure
```

Nothing on the server runs as root during a deploy, and the deploy key cannot open a shell.

---

## This server: 66.29.156.116 (AlmaLinux 9.7 · Apache 2.4.62 · PHP-FPM 8.3.33 · MySQL 8.0.45 · SELinux)

Inspected 2026-10-12. Apache serves ~20 other domains from `/etc/httpd/conf.d/`; Nginx is installed but not listening. The LMS is added beside them without editing any existing file:

| Piece | Where |
|---|---|
| Code, releases, shared config | `/var/www/dml-lms/` (`releases/`, `shared/.env`, `shared/storage/`, `current →`) |
| Apache site | `/etc/httpd/conf.d/dml-lms.conf` (temporary: IP, port 8088) · later `dml-lms-domain.conf` |
| PHP-FPM pool | `/etc/php-fpm.d/dml-lms.conf` → `/run/php-fpm/dml-lms.sock`, runs as `dmllms` |
| Database | `dml_lms`, user `dml_lms@localhost`, via the MySQL Unix socket |
| Deploy user | `dmldeploy` (owns releases; runs migrations; forced-command SSH key later) |
| SELinux | `shared/storage` labelled `httpd_sys_rw_content_t`; port 8088 added to `http_port_t` |

**First install** (as root): upload the release tarball, unpack only the installer, run it.

```bash
# on your Mac
scp ~/Downloads/<release-id>.tar.gz root@66.29.156.116:/root/
# on the server
mkdir -p /root/dml-install && tar -xzf /root/<release-id>.tar.gz -C /root/dml-install ./deploy
bash /root/dml-install/deploy/server/install-almalinux.sh /root/<release-id>.tar.gz
```

If MySQL root needs a password: `MYSQL_CMD="mysql -u root -p" bash …/install-almalinux.sh …`.

**Later releases by hand** (until GitHub Actions is wired up): `scp` the new tarball to `/root/`, then
```bash
install -o dmldeploy -m 600 /root/<id>.tar.gz /var/www/dml-lms/incoming/
sudo -u dmldeploy -H dml-deploy activate <id> $(sha256sum /root/<id>.tar.gz | cut -d' ' -f1)
```

**When `dmlschooloftechnology.com` points here** (A records for `@` and `www` → 66.29.156.116):
```bash
cp /var/www/dml-lms/current/deploy/apache/dml-lms-domain.conf /etc/httpd/conf.d/
apachectl configtest && systemctl reload httpd
certbot --apache -d dmlschooloftechnology.com -d www.dmlschooloftechnology.com
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console tenant:add-domain dml dmlschooloftechnology.com
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console tenant:add-domain dml www.dmlschooloftechnology.com
sed -i 's/^SESSION_SECURE_COOKIE=false/SESSION_SECURE_COOKIE=true/' /var/www/dml-lms/shared/.env
sed -i 's/^HEALTH_HOST=.*/HEALTH_HOST=dmlschooloftechnology.com/; s/^HEALTH_SCHEME=.*/HEALTH_SCHEME=https/; s/^HEALTH_PORT=.*/HEALTH_PORT=443/' ~dmldeploy/.dml-deploy.conf
# then retire the temporary port
rm /etc/httpd/conf.d/dml-lms.conf && apachectl configtest && systemctl reload httpd
firewall-cmd --permanent --remove-port=8088/tcp && firewall-cmd --reload
```

---

## 0. Inspect a server first (once, read-only)

The PRD requires the real server to be inspected before automation is finalised (§16.5). As root:

```bash
curl -fsSLO https://raw.githubusercontent.com/BankMoney24/dml-lms/main/deploy/server/inspect.sh   # or copy it over
bash inspect.sh > inspect.txt 2>&1
```

It changes nothing and prints no secrets. The answers decide which path below applies:

| inspect.sh shows | Use |
|---|---|
| `cPanel/WHM` | **Path A — cPanel** |
| Nginx, no control panel | **Path B — Nginx + PHP-FPM** |
| Plesk / DirectAdmin / other | Path A's ideas apply; adapt the docroot step |

Required either way: PHP ≥ 8.3 with `pdo_mysql`, `mbstring`, `intl`, `iconv`; MySQL 8.4 LTS (MariaDB 10.11+ works for staging); `curl`, `tar`, `flock`, `sha256sum`, `mysqldump`.

---

## Path A — cPanel / WHM

cPanel already isolates each account (its own Linux user, PHP-FPM pool and docroot), so the LMS gets **its own cPanel account** and the other domains are untouched.

1. **Account.** In WHM → *Create a New Account* for `dmlschooloftechnology.<tld>` (or use the existing account that owns the domain). Note the username, e.g. `dmlschool`.
2. **PHP.** WHM → *MultiPHP Manager*: set the domain to PHP 8.3+ (8.5 preferred once stable on the server). Enable `intl` in *EasyApache 4* if missing.
3. **Database.** cPanel → *MySQL Databases*: create `dmlschool_lms` and user `dmlschool_lms` with ALL PRIVILEGES on it.
4. **Directories** (as the account user, via WHM *Terminal* or `su - dmlschool`):
   ```bash
   mkdir -p ~/dml-lms/{releases,incoming,shared/storage/{logs,sessions,cache},shared/backups}
   chmod 750 ~/dml-lms ~/dml-lms/shared
   cp .env.example ~/dml-lms/shared/.env && chmod 640 ~/dml-lms/shared/.env   # then fill it in (step 6)
   printf '[client]\nuser=dmlschool_lms\npassword=DB_PASSWORD_HERE\n' > ~/.my.cnf && chmod 600 ~/.my.cnf
   ```
5. **Docroot.** Point the domain at `~/dml-lms/current/public`:
   * addon domain or subdomain: cPanel → *Domains* → *Manage* → Document Root = `dml-lms/current/public`;
   * account's main domain: `mv ~/public_html ~/public_html.orig && ln -s ~/dml-lms/current/public ~/public_html` (only after the first release exists).
   `public/.htaccess` handles routing and HTTPS. AutoSSL issues the certificate.
6. **Configuration.** Edit `~/dml-lms/shared/.env`: `APP_KEY` (`php -r 'echo bin2hex(random_bytes(32));'`), DB values, `APP_ENV=production`.
7. **Deploy scripts** (as root, once):
   ```bash
   install -m 755 deploy/server/dml-deploy deploy/server/dml-deploy-gate /usr/local/bin/
   ```
   As the account user: copy `deploy/server/dml-deploy.conf.example` to `~/.dml-deploy.conf` (chmod 600) with
   `APP_ROOT=/home/dmlschool/dml-lms`, `PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php`, `HEALTH_HOST=<your domain>`, `DB_NAME=dmlschool_lms`.
8. **Deploy key.** Continue with *GitHub setup* below; the public key goes into `/home/dmlschool/.ssh/authorized_keys`.
9. Make sure cPanel *SSH Access* is allowed for this account (WHM → *Manage Shell Access*: **jailed** shell is enough; the forced command ignores it anyway).

## Path B — Nginx + PHP-FPM (no panel)

As root, once:

```bash
# users: one to deploy, one to run PHP; PHP can read code, write only shared/storage
useradd --system --create-home --shell /bin/bash dmldeploy
useradd --system --no-create-home --shell /usr/sbin/nologin dmllms
usermod -aG dmldeploy dmllms

install -d -o dmldeploy -g dmldeploy -m 750 /var/www/dml-lms /var/www/dml-lms/{releases,incoming,shared,shared/backups}
install -d -o dmllms -g dmldeploy -m 770 /var/www/dml-lms/shared/storage /var/www/dml-lms/shared/storage/{logs,sessions,cache}
install -m 640 -o dmldeploy -g dmldeploy .env.example /var/www/dml-lms/shared/.env      # fill it in

install -m 755 deploy/server/dml-deploy deploy/server/dml-deploy-gate /usr/local/bin/
cp deploy/php-fpm/dml-lms.conf /etc/php/8.3/fpm/pool.d/ && systemctl reload php8.3-fpm
sed 's/DOMAIN/dmlschooloftechnology.<tld>/g' deploy/nginx/dml-lms.conf > /etc/nginx/sites-available/dml-lms.conf
ln -s ../sites-available/dml-lms.conf /etc/nginx/sites-enabled/
certbot certonly --webroot -w /var/www/letsencrypt -d dmlschooloftechnology.<tld> -d www.dmlschooloftechnology.<tld>
nginx -t && systemctl reload nginx

mysql -e "CREATE DATABASE dml_lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER 'dml_lms'@'localhost' IDENTIFIED BY '<strong password>';
          GRANT ALL ON dml_lms.* TO 'dml_lms'@'localhost';"
```

Then as `dmldeploy`: create `~/.dml-deploy.conf` from the example and `~/.my.cnf` (chmod 600).

Optional OPcache reload after a switch (not required — Nginx passes the resolved release path): add
`dmldeploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm` to `/etc/sudoers.d/dml-deploy` and set
`RELOAD_CMD="sudo -n /usr/bin/systemctl reload php8.3-fpm"`.

---

## GitHub setup (once)

1. **Deploy key** — on any machine: `ssh-keygen -t ed25519 -N "" -C github-deploy-production -f deploy_key`.
   On the server, add to the deploy user's `~/.ssh/authorized_keys` (one line):
   ```
   command="/usr/local/bin/dml-deploy-gate",no-agent-forwarding,no-port-forwarding,no-pty,no-X11-forwarding,no-user-rc ssh-ed25519 AAAA… github-deploy-production
   ```
2. **Host key** — from your computer: `ssh-keyscan -p 22 66.29.156.116` and check the fingerprint against the server's own (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server).
3. **Environments** — repo *Settings → Environments*:
   * `staging`: secrets below pointing at the staging account/path (e.g. `staging.dmlschooloftechnology.<tld>`).
   * `production`: same secrets for production, plus **Required reviewers** (you) and *Deployment branches and tags* limited to `v*` tags.
   | Secret | Value |
   |---|---|
   | `DEPLOY_HOST` | `66.29.156.116` |
   | `DEPLOY_PORT` | SSH port (22 unless changed) |
   | `DEPLOY_USER` | `dmlschool` (cPanel) or `dmldeploy` |
   | `DEPLOY_SSH_KEY` | contents of `deploy_key` (private) |
   | `DEPLOY_KNOWN_HOSTS` | output of `ssh-keyscan` from step 2 |
   Variable `APP_URL` = the site's https URL (shown on the deployment).
4. **Branch protection** on `main`: require a pull request, 1 approval, and status checks `Checks (PHP 8.3)`, `Secret scan`, `Asset budget`.
5. **Lockfile** — Actions → *Generate composer.lock* → Run. Merge the branch it pushes. (The build workspace that created this repo could not reach Packagist, so the lockfile is generated by CI once and reviewed like any other change.)

## First release

```bash
git tag v0.1.0 && git push origin v0.1.0
```
Approve the production job when staging is green. Then, once, on the server as the deploy user:

```bash
cd ~/dml-lms/current   # or /var/www/dml-lms/current
php bin/console tenant:create dml "DML School of Technology" dmlschooloftechnology.<tld>
php bin/console tenant:add-domain dml www.dmlschooloftechnology.<tld>
php bin/console user:create dml you@example.com "Your Name" owner     # prints a one-time password
```

(These console commands run under your own SSH login, not the restricted deploy key.)

## Day-to-day

| Task | How |
|---|---|
| Release | merge to `main` → tag `vX.Y.Z` → approve production |
| Roll back | Actions → re-run an older release's *production* job, **or** `ssh deploy@host rollback` with the deploy key |
| Status | `ssh -i deploy_key deploy@host status` |
| Deploy history | `~/dml-lms/deploy.log` (JSON lines: release, result, source IP) and the Actions run summary |
| Pre-migration backups | `shared/backups/pre-<release>.sql.gz` (last 10 kept) |

## Rules (from PRD §16.4)

* Never `git pull` on the server and never deploy as root.
* Migrations must be backward compatible with the previous release (expand → migrate → contract over separate releases), because rollback only switches code.
* `.env`, uploads, logs and backups live in `shared/`, never inside a release.
* Off-host backups and a restore drill are still required before real learner data (PRD §13.2) — see `docs/status.md`.
