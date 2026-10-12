HTTPS encrypts traffic between visitors and your server and proves the site is really yours. Browsers warn users away from sites without it, and passwords sent over plain HTTP can be read by anyone on the same network. Certificates from **Let's Encrypt** are free and renew automatically.

## Before you start

1. The domain's **DNS A record** (and AAAA for IPv6, if you use it) points to your server. Check: `dig +short school.example.org`.
2. Port **80** and **443** are open in the firewall. Let's Encrypt checks port 80 to confirm you control the domain.
3. Your web server already answers for the domain on port 80 (previous lesson).

## Install certbot and get a certificate

```bash
# AlmaLinux (EPEL)
sudo dnf install certbot python3-certbot-nginx
# Ubuntu
sudo apt install certbot python3-certbot-nginx

sudo certbot --nginx -d school.example.org -d www.school.example.org
```

For Apache use the `python3-certbot-apache` package and `--apache`. certbot asks for an email address (for expiry warnings), obtains the certificate, edits your server block to use it, and adds a redirect from HTTP to HTTPS.

The certificate files are stored in `/etc/letsencrypt/live/school.example.org/`:

| File | Contains |
|---|---|
| `fullchain.pem` | your certificate plus the intermediate certificates |
| `privkey.pem` | the private key; keep it secret (root only) |

## Renewal

Let's Encrypt certificates last **90 days**. The package installs a systemd timer (or cron job) that renews them automatically when they have about 30 days left.

```bash
systemctl list-timers | grep -i certbot
sudo certbot renew --dry-run       # proves renewal will work, without changing anything
sudo certbot certificates          # what you have and when each expires
```

> **Warning:** Renewal fails silently if port 80 gets closed, DNS changes, or the web server configuration breaks. Check expiry dates as part of your monitoring (module 7); an expired certificate takes your site down for every visitor at once.

## Checking a certificate

```bash
curl -I https://school.example.org
echo | openssl s_client -connect school.example.org:443 -servername school.example.org 2>/dev/null | openssl x509 -noout -dates -subject
```

## Good HTTPS settings

certbot's defaults are sensible. Once everything works over HTTPS, consider adding the **HSTS** header, which tells browsers to use only HTTPS for your domain in future:

```text
add_header Strict-Transport-Security "max-age=31536000" always;
```

> **Important:** Only add HSTS when you are sure the site, and every subdomain you include, will always have a working certificate. Browsers remember it for the whole `max-age`, and visitors cannot click past an HSTS error.

After switching to HTTPS, look for **mixed content**: pages served over HTTPS that load images or scripts over `http://`. Browsers block those. Fix the links in your application, usually by using relative URLs.

## Try it

If you have a domain (a cheap one, or a subdomain your school gives you), point it at your server and get a certificate for it. Run `sudo certbot renew --dry-run` and `sudo certbot certificates`. If you have no domain yet, read the certbot output carefully in a classmate's or teacher's demonstration, and note the DNS step you would need.
