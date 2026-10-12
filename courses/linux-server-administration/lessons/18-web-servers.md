A web server receives HTTP requests and returns pages, files, or the output of an application. The two you will meet most are **Nginx** and **Apache httpd**. Both can host many websites on one server, each chosen by the domain name in the request.

## Where things live

| | Nginx (AlmaLinux) | Nginx (Ubuntu) | Apache (AlmaLinux) | Apache (Ubuntu) |
|---|---|---|---|---|
| Package / service | `nginx` | `nginx` | `httpd` | `apache2` |
| Site configuration | `/etc/nginx/conf.d/*.conf` | `/etc/nginx/sites-available/` + link in `sites-enabled/` | `/etc/httpd/conf.d/*.conf` | `/etc/apache2/sites-available/` + `a2ensite` |
| Logs | `/var/log/nginx/` | `/var/log/nginx/` | `/var/log/httpd/` | `/var/log/apache2/` |
| Runs as user | `nginx` | `www-data` | `apache` | `www-data` |

This lesson uses Nginx; the Apache equivalent is shown at the end.

## One server, many sites

Each website gets its own **server block** (Nginx) or **virtual host** (Apache). The web server compares the `Host` the browser asked for with each block's `server_name`.

Create the site's files:

```bash
sudo mkdir -p /var/www/school.example.org/public
echo '<h1>It works</h1>' | sudo tee /var/www/school.example.org/public/index.html
```

`/etc/nginx/conf.d/school.example.org.conf` (on Ubuntu: `/etc/nginx/sites-available/school.example.org`, then `sudo ln -s ../sites-available/school.example.org /etc/nginx/sites-enabled/`):

```text
server {
    listen 80;
    listen [::]:80;
    server_name school.example.org www.school.example.org;

    root /var/www/school.example.org/public;
    index index.html index.php;

    access_log /var/log/nginx/school.access.log;
    error_log  /var/log/nginx/school.error.log;

    location / {
        try_files $uri $uri/ =404;
    }

    # Never serve hidden files such as .env or .git
    location ~ /\. {
        deny all;
    }
}
```

## Test, then reload

```bash
sudo nginx -t                  # always test first
sudo systemctl reload nginx
curl -H "Host: school.example.org" http://127.0.0.1/
```

The `curl -H "Host: …"` trick tests a site before its DNS points to the server.

> **Tip:** Get into the habit of **test, then reload**: `sudo nginx -t && sudo systemctl reload nginx`. The `&&` only runs the reload if the test passed, so a typing mistake never takes your websites down.

## Permissions for web files

The web server's user must be able to **read** the files and **enter** every folder on the path; it should **not** be able to write to them, except to specific folders like `uploads`.

```bash
sudo chown -R ada:ada /var/www/school.example.org        # you edit the files
sudo find /var/www/school.example.org -type d -exec chmod 755 {} \;
sudo find /var/www/school.example.org -type f -exec chmod 644 {} \;
```

On AlmaLinux, SELinux must also allow it (module 7). Files under `/var/www` get the right label automatically; files you **move** there from your home folder keep their old label. Fix with `sudo restorecon -Rv /var/www`.

## Running PHP: PHP-FPM

Web servers do not run PHP themselves. They pass `.php` requests to **PHP-FPM**, a separate service with a pool of PHP workers:

```text
location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php-fpm/www.sock;   # Ubuntu: unix:/run/php/php8.3-fpm.sock
}
```

Each application can have its own FPM pool running as its own user, so one compromised site cannot read another's files.

## Reading the logs

```bash
sudo tail -f /var/log/nginx/school.access.log /var/log/nginx/school.error.log
```

| Status | Usually means |
|---|---|
| 403 Forbidden | permissions, SELinux, or no index file |
| 404 Not Found | wrong `root`, or the file is not there |
| 502 Bad Gateway | the backend (PHP-FPM, an app) is down or the socket path is wrong |
| 504 Gateway Timeout | the backend is too slow |

## The Apache version

```text
<VirtualHost *:80>
    ServerName school.example.org
    ServerAlias www.school.example.org
    DocumentRoot /var/www/school.example.org/public
    <Directory /var/www/school.example.org/public>
        Require all granted
        AllowOverride None
    </Directory>
    ErrorLog /var/log/httpd/school.error.log
    CustomLog /var/log/httpd/school.access.log combined
</VirtualHost>
```

Test with `sudo apachectl configtest`, then `sudo systemctl reload httpd` (Ubuntu: `apache2`).

## Try it

Host two different sites on your server, `one.test` and `two.test`, each with its own folder and log files. Prove with `curl -H "Host: …"` that each name returns its own page.
