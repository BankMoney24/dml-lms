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
| Database | `dml_lms`, user `dml_lms@127.0.0.1`, over TCP (the socket directory is not readable by the app users) |
| Deploy user | `dmldeploy` (owns releases; runs migrations; forced-command SSH key later) |
| Email worker | `/etc/cron.d/dml-lms`: `mail:work` every minute as `dmllms` |
| Uploaded files | `shared/storage/uploads/<school>/<year>/<month>/<random>` (included in the nightly backup) |
| Backups | `/var/backups/dml-lms/` (encrypted), settings and key in `/etc/dml-lms/`, timers `dml-backup` and `dml-restore-check` |
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
sudo -u dmldeploy -H /usr/local/bin/dml-deploy activate <id> $(sha256sum /root/<id>.tar.gz | cut -d' ' -f1)
```

**When `dmlschooloftechnology.org` points here** (A records for `@` and `www` → 66.29.156.116; confirmed on 14 Oct 2026). One script does the rest (ADR 0019): it checks DNS, backs up the Apache config and `.env`, adds the domain's Apache site, maps both names to the school, gets a Let's Encrypt certificate with HTTP→HTTPS, makes the domain the school's canonical address (`PUBLIC_SCHEME=https`, `CANONICAL_REDIRECT=true`, secure cookies, HSTS for a year without subdomains), points the deploy health check at it, and checks the result. It stops at the first problem and is safe to run again.
```bash
bash /var/www/dml-lms/current/deploy/server/setup-domain.sh dmlschooloftechnology.org <your-email-for-certificate-notices>
# when the old address is no longer needed:
rm /etc/httpd/conf.d/dml-lms.conf && apachectl configtest && systemctl reload httpd
firewall-cmd --permanent --remove-port=8088/tcp && firewall-cmd --reload
```
Undo: the script prints the exact commands (restore the backup tarball, remove `dml-lms-domain*.conf`, and set the old primary address back with `tenant:primary-domain`).

### Backups (nightly, encrypted, restore-tested weekly)

New installs set this up automatically. On a server installed before this release, run once as root:

```bash
SRC=/var/www/dml-lms/current
install -m 755 $SRC/deploy/server/dml-backup $SRC/deploy/server/dml-restore /usr/local/bin/
install -d -m 700 /etc/dml-lms /var/backups/dml-lms
[ -f /etc/dml-lms/backup.conf ] || install -m 600 $SRC/deploy/server/backup.conf.example /etc/dml-lms/backup.conf
[ -s /etc/dml-lms/backup.key ] || (umask 077; openssl rand -base64 48 > /etc/dml-lms/backup.key)
install -m 644 $SRC/deploy/systemd/dml-*.service $SRC/deploy/systemd/dml-*.timer /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now dml-backup.timer dml-restore-check.timer
dml-backup && dml-restore verify latest
cat /etc/dml-lms/backup.key      # save this OFF the server now (password manager)
```

Then set `REMOTE` so copies leave the server, and let yourself see the System page:

```bash
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console user:platform-admin you@example.com
```

Everything else (what is in a backup, restoring, losing the whole server) is in **[backup-restore.md](backup-restore.md)**.

### Uploaded files

Lesson files and assignment files are stored in `/var/www/dml-lms/shared/storage/uploads/` (outside the web root, readable only through the app after an access check). The nightly backup includes them.

Limits, in `shared/.env`:

* `UPLOAD_MAX_MB`: per file, default 10.
* `MEDIA_MAX_MB`: per video or audio file (lesson media, class recordings), default 200 (ADR 0029).
* `UPLOAD_TENANT_QUOTA_MB`: per school, default 5120.

The PHP pool allows uploads of up to 210 MB (`upload_max_filesize` 210M, `post_max_size` 215M), with 30 minutes for slow connections. The Apache site caps request bodies at 220 MB. If you raise `MEDIA_MAX_MB`, raise these too; the app always tells people the lower of its own limit and PHP's.

Install the pool file, and set the Apache cap, after updating to release 29 (and after any first install):

```bash
install -m 644 /var/www/dml-lms/current/deploy/php-fpm/dml-lms-almalinux.conf /etc/php-fpm.d/dml-lms.conf
# cap request bodies at 220 MB in the Apache site (replaces an older 50 MB cap)
if grep -q LimitRequestBody /etc/httpd/conf.d/dml-lms.conf; then
  sed -i 's/^\(\s*\)LimitRequestBody .*/\1LimitRequestBody 230686720/' /etc/httpd/conf.d/dml-lms.conf
else
  sed -i '/ErrorLog \/var\/log\/httpd\/dml-lms-error.log/i\    LimitRequestBody 230686720' /etc/httpd/conf.d/dml-lms.conf
fi
php-fpm -t && apachectl configtest && systemctl reload php-fpm httpd
```

Video is played in 4 MB pieces (HTTP range requests), so a class watching recordings does not tie up PHP workers. A full download of a large recording does hold one worker until it finishes.

**Virus scanning (recommended):** install ClamAV and point the app at it.

```bash
dnf install -y clamav clamd clamav-update          # from EPEL
sed -i 's/^Example/#Example/; s/^#\?TCPSocket .*/TCPSocket 3310/; s/^#\?TCPAddr .*/TCPAddr 127.0.0.1/' /etc/clamd.d/scan.conf
sed -i 's/^Example/#Example/' /etc/freshclam.conf
freshclam && systemctl enable --now clamd@scan clamav-freshclam
setsebool -P antivirus_can_scan_system 1
setsebool -P httpd_can_network_connect 1           # lets PHP-FPM reach clamd on 127.0.0.1:3310
printf 'UPLOAD_SCANNER=clamd\nCLAMD_HOST=127.0.0.1\nCLAMD_PORT=3310\n' >> /var/www/dml-lms/shared/.env
systemctl reload php-fpm
```

With scanning on, uploads are refused while clamd is down (fail closed). clamd needs about 1 GB of memory.

For video and audio, clamd must accept files as large as `MEDIA_MAX_MB`. Otherwise those uploads are refused with a message saying the file is bigger than the scanner checks:

```bash
sed -i 's/^#\?StreamMaxLength .*/StreamMaxLength 210M/; s/^#\?MaxFileSize .*/MaxFileSize 210M/; s/^#\?MaxScanSize .*/MaxScanSize 420M/' /etc/clamd.d/scan.conf
grep -q '^StreamMaxLength' /etc/clamd.d/scan.conf || echo 'StreamMaxLength 210M' >> /etc/clamd.d/scan.conf
systemctl restart clamd@scan
```

### Courses that ship with the platform

Release packages include ready-made courses in `courses/`. To add one to the school (once per course; importing again makes a second copy):

```bash
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console course:packages
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console course:import dml linux-server-administration --publish --author=you@example.com
```

Without `--publish` it arrives as a draft for teachers to review first. A course imported before its package had files gets them with `course:sync-files dml linux-server-administration linux-server-administration` (it skips files already there). Revoke a certificate issued by mistake with `bin/console certificate:revoke ABCD-EFGH-JKLM "reason"`; the reason is shown on the public `/verify` page.

### Virtual labs

Labs are a lesson type. Teachers add them from **Teaching → a course → Add a lesson → Lab**, and try every lab first at **Teaching → Lab catalogue** (practice runs are not saved). This release has six: Ohm's law, titration and straight-line graphs (school subjects), and subnetting, a JavaScript function and phishing (skills). `bin/console lab:list` lists them.

Nothing extra runs on the server: simulations run in the learner's browser, and learner code runs in a sandboxed frame with no network access (ADR 0011). The server only starts sessions, checks they belong to the learner, and marks answers. One cron line closes sessions whose time ran out. **After this release, reinstall the cron file once:**

```bash
install -m 644 /var/www/dml-lms/current/deploy/cron/dml-lms /etc/cron.d/dml-lms
```

Two ready-made courses use the labs. Import them (as drafts, or add `--publish`):

```bash
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console course:import dml ss2-science-practicals --author=you@example.com
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console course:import dml practical-tech-skills --author=you@example.com
```

### Brand (DML Technologies)

The DML logo, icons and share image ship in `public/assets/brand/`. Apply the logo, the DML blue theme and the tagline to the DML school once:

```bash
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console school:brand dml --logo=/var/www/dml-lms/current/public/assets/brand/dml-mark.png --theme=dml --tagline="Developing leaders through specialised education"
```

### Search engines

Each school address serves `/robots.txt` and `/sitemap.xml` (public pages only, on the canonical address). Once `dmlschooloftechnology.org` is on HTTPS, add it in Google Search Console and Bing Webmaster Tools (DNS TXT verification, or paste the meta-tag code under School → Website), and submit `https://dmlschooloftechnology.org/sitemap.xml`. Signed-in pages tell search engines not to index them. Full checklist: `docs/seo.md`.

### Classes and cohorts

Nothing to install. Choose what your school calls them (class, cohort, group, stream or set) under **School → Settings → Type and academic year**, then create them from the new menu item. Learners in a class are enrolled in the courses set for it.

### Online classroom (Jitsi Meet, ADR 0029 and 0030)

Live classes and meetings run inside the site, on the school's own Jitsi Meet. On 66.29.156.116 it runs **in Docker on the same server**, next to the other sites, with Apache passing `live.dmlschooloftechnology.org` to it. This is how it was set up (October 2026); each step leaves every other site and service unchanged.

**What is where**

| Part | Where |
|---|---|
| Jitsi (stable release, Docker) | `/opt/jitsi/jitsi-docker-jitsi-meet-*` (`.env`, `docker-compose.override.yml`), data in `/opt/jitsi-meet-cfg` |
| Ports | web 127.0.0.1:8510 (and 8543), jicofo 127.0.0.1:8588, videobridge 127.0.0.1:8580 and **UDP 10000** open to the world (firewalld zone `iredmail`). Port 8000 belongs to Tridax: do not use it. |
| Apache | `/etc/httpd/conf.d/live-jitsi.conf`: port 80 for Let's Encrypt and a redirect, port 443 proxying to 127.0.0.1:8510 with WebSockets (`upgrade=websocket`) |
| Certificate | certbot, webroot `/var/www/live-acme`, renews automatically and reloads Apache |
| Token secret | `/root/jitsi-jwt-secret.txt` (root only) and `JWT_APP_SECRET` in Jitsi's `.env`; in the LMS sealed with APP_KEY |
| Limits | `docker-compose.override.yml`: web 256 MB, prosody 512 MB, jicofo 1 GB, videobridge 2.5 GB and 4 cores |

**Jitsi `.env` settings that matter**

```
HTTP_PORT=127.0.0.1:8510   HTTPS_PORT=127.0.0.1:8543   JVB_COLIBRI_PORT=8580   JICOFO_REST_PORT=8588
PUBLIC_URL=https://live.dmlschooloftechnology.org   JVB_ADVERTISE_IPS=66.29.156.116   TZ=Africa/Lagos
DISABLE_HTTPS=1   ENABLE_HTTP_REDIRECT=0   ENABLE_LETSENCRYPT=0   ENABLE_WELCOME_PAGE=0
ENABLE_AUTH=1   ENABLE_GUESTS=0   AUTH_TYPE=jwt   JWT_APP_ID=dml-lms   JWT_APP_SECRET=<the secret>
JWT_ACCEPTED_ISSUERS=dml-lms   JWT_ACCEPTED_AUDIENCES=jitsi   JWT_ALLOW_EMPTY=0
XMPP_MUC_MODULES=token_affiliation   ENABLE_AUTO_OWNER=0
JICOFO_MAX_MEMORY=768m   VIDEOBRIDGE_MAX_MEMORY=2048m
```

The containers run as uid 1000 and must own their storage: `chown -R 1000:1000 /opt/jitsi-meet-cfg/storage` (prosody stops at start with "not writable" otherwise).

**Checks**

```bash
cd /opt/jitsi/jitsi-docker-jitsi-meet-*
docker compose ps                                             # four containers Up
docker compose exec prosody grep -nE 'authentication|allow_empty_token|token_affiliation' /run/prosody/config/conf.d/jitsi-meet.cfg.lua
docker compose exec jicofo grep -n 'auto-owner' /run/jicofo/config/jicofo.conf   # enable-auto-owner = false
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console classroom:check dml
```

**Connect the LMS** without copying the secret anywhere:

```bash
cat /root/jitsi-jwt-secret.txt | sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console classroom:connect dml --domain=live.dmlschooloftechnology.org --app-id=dml-lms
```

(or paste it under **School → Classroom**; the page also has **Check the connection**).

**Change the secret** (for example if it was ever shown or shared):

```bash
cd /opt/jitsi/jitsi-docker-jitsi-meet-*
openssl rand -hex 32 > /root/jitsi-jwt-secret.txt && chmod 600 /root/jitsi-jwt-secret.txt
sed -i -E "s|^JWT_APP_SECRET=.*|JWT_APP_SECRET=$(cat /root/jitsi-jwt-secret.txt)|" .env
docker compose up -d
cat /root/jitsi-jwt-secret.txt | sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console classroom:connect dml --domain=live.dmlschooloftechnology.org --app-id=dml-lms
```

Classes or meetings in progress drop and must rejoin; do it outside class hours.

**Update Jitsi**: download the newer release next to the old one, copy `.env` and `docker-compose.override.yml` across, `docker compose down` in the old folder and `docker compose up -d` in the new one.

**Undo everything**

```bash
cd /opt/jitsi/jitsi-docker-jitsi-meet-* && docker compose down
rm -f /etc/httpd/conf.d/live-jitsi.conf && apachectl configtest && systemctl reload httpd
firewall-cmd --zone=iredmail --remove-port=10000/udp; firewall-cmd --permanent --zone=iredmail --remove-port=10000/udp
systemctl disable --now docker docker.socket
```

**On a separate server instead** (more capacity later): Ubuntu 24.04 or Debian 12 with the official quick install (`apt install jitsi-meet jitsi-meet-tokens`), the same app id, secret and moderator settings, and the A record for `live.` pointed at it.

Capacity: about one class of 30 at a time on this server. In Data saver, expect very roughly 3–5 GB of upload per class hour (not measured); watch the VPS bandwidth allowance. Recording is not included; teachers upload recordings to the class page.

### Payments (Paystack)

Schools sell courses through their own Paystack account (ADR 0012). PHP has to make outgoing HTTPS calls to `api.paystack.co`; SELinux blocks that by default, so allow it once:

```bash
setsebool -P httpd_can_network_connect on
```

Then, signed in as a school admin:

1. **School → Payments**: paste the Paystack **test** secret key (`sk_test_…`) and choose the currency. The key is checked with Paystack, stored encrypted and never shown again.
2. Copy the webhook address shown there into Paystack: Settings → API Keys & Webhooks → Test Webhook URL.
3. Set a price on a course: **Teaching → the course → Price**.
4. Sign in as a learner, pay with a Paystack test card, and check the course opens and the payment shows under School → Payments.
5. When ready, connect the **live** key and paste the same webhook address as the Live Webhook URL. Paystack only sends live webhooks to `https://` addresses, so put the domain on HTTPS first; until then payments are still confirmed when the learner returns from Paystack.

Money paid by bank transfer or cash: **School → Payments → Record a payment**. It enrols the learner at once.

### Email (password reset, invitations)

Until `MAIL_DRIVER=smtp` is set, nothing is emailed: admins get a one-time password to pass on, and *Forgotten your password?* tells people to ask an administrator. To turn email on:

1. **Pick a sender.** Either a transactional email provider (Brevo, Mailgun, Postmark, Amazon SES, Zoho ZeptoMail… any that offers SMTP), or the mail server already on this machine if it is set up to send for other domains. A provider is the safer choice for reaching Gmail/Outlook inboxes.
2. **Authorise the domain** at your DNS host with the records the provider gives you: SPF (TXT), DKIM (TXT/CNAME) and ideally DMARC (`_dmarc` TXT, e.g. `v=DMARC1; p=none; rua=mailto:you@…`). Without these, mail lands in spam or is rejected.
3. **Add the settings** to `/var/www/dml-lms/shared/.env` (keep `chmod 640`):
   ```ini
   MAIL_DRIVER=smtp
   MAIL_HOST=smtp.your-provider.example
   MAIL_PORT=587
   MAIL_ENCRYPTION=tls
   MAIL_USERNAME=…
   MAIL_PASSWORD=…
   MAIL_FROM_ADDRESS=no-reply@dmlschooloftechnology.org
   MAIL_FROM_NAME="DML School of Technology"
   ```
   Local relay on this server instead: `MAIL_HOST=127.0.0.1`, `MAIL_PORT=25`, `MAIL_ENCRYPTION=none`, no username/password. SELinux must let PHP-FPM reach mail ports (25, 465, 587): `setsebool -P httpd_can_sendmail on`. A provider on any other port also needs `httpd_can_network_connect`.
4. **Reload and test:**
   ```bash
   systemctl reload php-fpm
   sudo -u dmllms php /var/www/dml-lms/current/bin/console mail:test you@example.com
   sudo -u dmllms php /var/www/dml-lms/current/bin/console mail:status
   ```
5. **Cron** (the installer adds it; for an existing install run once):
   `install -m 644 /var/www/dml-lms/current/deploy/cron/dml-lms /etc/cron.d/dml-lms`

How it behaves: messages go into the `mail_outbox` table and are sent right after the page is returned; failures are retried after 1 min, 5 min, 30 min, 2 h and 6 h, then marked failed. Message bodies (which contain sign-in links) are erased once sent or abandoned. Reset links last 60 minutes, invitations 7 days; both work once.

### School setup and new schools

The first time an owner or admin signs in after this release, the home page asks them to **set up the school**: type, academic year, name, colour, logo, contact details, certificates, whether visitors can browse courses, starter courses, and the first teachers to invite. Nothing changes until they confirm the last step. Later changes go through the same steps from **School → Settings**.

Platform administrators (`bin/console user:platform-admin`) create more schools at **System → Schools**: name, web address and first owner. Each school needs its own address that points at this server.

#### A new school address

1. **DNS**: at the domain's DNS host add an A record for the name (e.g. `learn.sunriseacademy.ng`) pointing at the server's IP address.
2. **Web server**: add the name to the domain virtual host, then reload:
   ```bash
   sed -i 's/^\(\s*ServerAlias .*\)$/\1 learn.sunriseacademy.ng/' /etc/httpd/conf.d/dml-lms-domain.conf
   apachectl configtest && systemctl reload httpd
   ```
   (On the temporary IP-and-port setup every name that reaches the port already lands on the app.)
3. **HTTPS**: `certbot --apache --expand -d dmlschooloftechnology.org -d www.dmlschooloftechnology.org -d learn.sunriseacademy.ng` (list every name the certificate should cover). Make the school's own name canonical with `bin/console tenant:primary-domain <slug> <host>`.
4. **In the app**: System → Schools → Add a school (or open the school and *Add an address*). The address works as soon as DNS and the web server do. The owner gets an invitation by email, or you see a one-time password to give them.

### Two-step sign-in

Owners and admins must set up an authenticator app (Google Authenticator, Microsoft Authenticator, Authy) the next time they sign in, and get 10 one-use recovery codes. Change who is forced with `TWO_FACTOR_REQUIRED_ROLES` in `shared/.env` (comma-separated roles; empty = optional for everyone). If someone loses their phone and codes, another **owner** resets it from *People → Manage*. If the only owner is locked out:
```bash
mysql dml_lms -e "UPDATE users SET totp_secret=NULL, totp_enabled_at=NULL, totp_last_step=NULL, session_version=session_version+1 WHERE email='owner@example.com'; DELETE rc FROM recovery_codes rc JOIN users u ON u.id=rc.user_id WHERE u.email='owner@example.com';"
```
Two-step secrets are encrypted with `APP_KEY`; changing `APP_KEY` means everyone sets it up again.

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
* Backups must be copied off the server (`REMOTE`) and the backup key kept off the server before real learner data arrives (PRD §13.2). See [backup-restore.md](backup-restore.md).

## A sample school for testing (ADR 0027)

To try every screen with realistic data, create a separate sample school. It never touches the real school.

1. Point a DNS record for the sample address (for example `sample.dmlschooloftechnology.org`) at the server and add it to the web server and certificate, as for the main domain.
2. Fill it:

```
sudo -u dmldeploy -H php /var/www/dml-lms/current/bin/console sample:seed sample --create="DML Sample School" --host=sample.dmlschooloftechnology.org --size=medium
```

The command prints one password for every sample account (owner@sample.test, admin@sample.test, and so on). Write it down; it is not shown again. `--size=small|medium|large` sets 24, 60 or 150 learners. Its robots.txt keeps search engines out unless you add `--public`. When you no longer need it, suspend it from the System page.

