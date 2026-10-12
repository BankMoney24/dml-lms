A package manager installs software together with everything it depends on, keeps track of what is installed, and updates it all in one command. Never download random installers onto a server when a package exists.

## The two package managers

| Task | AlmaLinux (`dnf`) | Ubuntu (`apt`) |
|---|---|---|
| Refresh the package lists | (automatic) | `sudo apt update` |
| Install updates | `sudo dnf upgrade` | `sudo apt upgrade` |
| Install a package | `sudo dnf install nginx` | `sudo apt install nginx` |
| Remove a package | `sudo dnf remove nginx` | `sudo apt remove nginx` |
| Search | `dnf search php` | `apt search php` |
| Details about a package | `dnf info nginx` | `apt show nginx` |
| Which package owns a file? | `dnf provides /usr/bin/dig` | `dpkg -S /usr/bin/dig` |
| List installed | `dnf list installed` | `apt list --installed` |

> **Note:** On Ubuntu, `apt update` only downloads the latest list of what is available. It changes nothing. `apt upgrade` is what actually installs updates. People mix these up constantly.

## Repositories

Packages come from **repositories**: signed collections hosted by the distribution or a vendor. The base repositories are configured in `/etc/yum.repos.d/` (AlmaLinux) or `/etc/apt/sources.list.d/` (Ubuntu).

On AlmaLinux, many common extra tools (such as `fail2ban` and `htop`) are in **EPEL**:

```bash
sudo dnf install epel-release
```

Add other third-party repositories only from vendors you trust, and only when you need them: every repository you add can install software as root.

## Updating safely

Security fixes arrive constantly. A routine that works:

1. Read what will change: `sudo dnf check-update` or `apt list --upgradable`.
2. Take a snapshot or make sure backups are current (module 5).
3. Apply: `sudo dnf upgrade` or `sudo apt update && sudo apt upgrade`.
4. Check whether a reboot is needed:

```bash
sudo dnf needs-restarting -r           # AlmaLinux (from dnf-plugins-core / dnf-utils)
cat /var/run/reboot-required 2>/dev/null   # Ubuntu: the file exists if a reboot is needed
```

5. Reboot at a quiet time if needed, then check your services came back (module 3).

If an update breaks something on AlmaLinux, `dnf history` lists every transaction and `sudo dnf history undo <id>` reverses one.

## Automatic security updates

For small servers, automatic **security** updates are usually safer than forgetting to patch:

```bash
# AlmaLinux
sudo dnf install dnf-automatic
sudo sed -i 's/^upgrade_type.*/upgrade_type = security/; s/^apply_updates.*/apply_updates = yes/' /etc/dnf/automatic.conf
sudo systemctl enable --now dnf-automatic.timer

# Ubuntu (installed by default on Ubuntu Server)
sudo apt install unattended-upgrades
sudo dpkg-reconfigure -plow unattended-upgrades
```

Automatic updates do not reboot by default, so still check for pending reboots regularly.

## Try it

1. Install `htop` and `tree` (on AlmaLinux, `htop` needs EPEL).
2. Run `tree -L 1 /etc | head` to see what `tree` does.
3. Find which package provides the `dig` command, and install it (`bind-utils` on AlmaLinux, `dnsutils` on Ubuntu).
