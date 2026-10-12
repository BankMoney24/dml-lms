Long-running programs such as web servers and databases run as **services**. On modern Linux, services are managed by **systemd**, and their messages go to the **journal**. These two tools are what you will use most when something is wrong.

## Controlling services

```bash
systemctl status nginx            # running? since when? last log lines
sudo systemctl start nginx        # start now
sudo systemctl stop nginx         # stop now
sudo systemctl restart nginx      # stop then start (brief outage)
sudo systemctl reload nginx       # re-read configuration without stopping, if supported
sudo systemctl enable nginx       # start automatically at boot
sudo systemctl enable --now nginx # enable and start in one step
sudo systemctl disable nginx      # do not start at boot
systemctl is-active nginx         # prints active or inactive (handy in scripts)
systemctl is-enabled nginx
systemctl --failed                # every service that has failed
```

> **Note:** **Start** and **enable** are different. `start` runs it now; `enable` makes it start after the next reboot. A service that is started but not enabled is a classic surprise after a reboot. Use `enable --now`.

Service names differ a little between families: the Apache web server is `httpd` on AlmaLinux and `apache2` on Ubuntu; SSH is `sshd` on AlmaLinux and `ssh` on Ubuntu.

## Reading status output

```text
● nginx.service - The nginx HTTP and reverse proxy server
     Loaded: loaded (/usr/lib/systemd/system/nginx.service; enabled; preset: disabled)
     Active: active (running) since Mon 2026-10-12 09:01:44 UTC; 2h 3min ago
   Main PID: 812 (nginx)
```

**Loaded** shows where the unit file is and whether it is enabled. **Active** shows the state. If it says `failed`, the last log lines underneath usually tell you why.

## The journal

```bash
sudo journalctl -u nginx               # all messages from one service
sudo journalctl -u nginx -n 50         # last 50 lines
sudo journalctl -u nginx -f            # follow live
sudo journalctl -u nginx --since "1 hour ago"
sudo journalctl -p err -b              # errors since the last boot
sudo journalctl -b -1                  # the previous boot (useful after a crash)
```

## Writing your own service

Suppose you have a small application at `/opt/notes/server` that should run all the time, as its own user, and restart if it crashes. Create `/etc/systemd/system/notes.service`:

```ini
[Unit]
Description=Notes web application
After=network-online.target
Wants=network-online.target

[Service]
User=notes
Group=notes
WorkingDirectory=/opt/notes
ExecStart=/opt/notes/server --port 8080
Restart=on-failure
RestartSec=5
# Basic hardening: the app cannot change system files or see other users' homes
ProtectSystem=full
ProtectHome=true
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin notes
sudo systemctl daemon-reload            # tell systemd about the new file
sudo systemctl enable --now notes
systemctl status notes
```

Run `daemon-reload` every time you change a unit file. Never edit files under `/usr/lib/systemd/system/` directly: package updates overwrite them. To change a packaged service, use `sudo systemctl edit nginx`, which creates an override file that survives updates.

## Try it

1. Install nginx (`sudo dnf install nginx` or `sudo apt install nginx`), then enable and start it.
2. Check that it is enabled and active. Reboot the server and check again.
3. Stop it, run `systemctl status nginx`, then read the last ten journal lines for it.
