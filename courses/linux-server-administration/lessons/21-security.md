Security on a server is not one product you install. It is a set of habits that make every attack harder and every mistake smaller. You have already practised several: keys instead of passwords, a firewall, least-privilege users. This lesson adds the rest of the routine, and the one tool that confuses newcomers most: SELinux.

## The habits

1. **Patch.** Apply security updates weekly at least, or automatically (module 3). Most break-ins use holes that already had a fix.
2. **Run less.** Every running service is something to attack. `systemctl list-units --type=service --state=running`; disable what you do not need.
3. **Expose less.** Only the ports the server is for are open (module 4); internal services listen on `127.0.0.1`.
4. **Least privilege.** Each application runs as its own user, can write only where it must, and has a database user limited to its own database.
5. **Protect secrets.** Passwords and keys live in files readable only by the user that needs them (`chmod 600` or `640`), never in the web root, never in Git.
6. **Know what happened.** Logs are kept, and you look at them (next lesson).
7. **Be able to recover.** Tested backups, stored off the server (module 5).

## SELinux (AlmaLinux and the Red Hat family)

SELinux adds a second check on top of normal permissions. Every process and every file has a **label**, and a policy says which labels may touch which. Even if a web server process is taken over, SELinux stops it from reading files that are not labelled as web content, or from opening network connections it was never meant to make.

```bash
getenforce               # Enforcing, Permissive or Disabled
ls -Z /var/www/html      # file labels
ps -eZ | grep nginx      # process labels
```

> **Warning:** The most common bad advice on the internet is "just disable SELinux". Do not. Learn the three fixes below; they solve nearly every SELinux problem you will meet.

**1. Files have the wrong label.** Files moved (not copied) from your home folder keep their home-folder label, so the web server is refused with a 403 even though permissions look right.

```bash
sudo restorecon -Rv /var/www/school.example.org
```

For a custom location, first tell SELinux what label it should have, then apply it:

```bash
sudo semanage fcontext -a -t httpd_sys_content_t "/srv/sites(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/srv/sites/school/uploads(/.*)?"
sudo restorecon -Rv /srv/sites
```

**2. A feature is switched off by a boolean.** For example, the web server may not connect to a database or another server over the network by default:

```bash
getsebool -a | grep httpd
sudo setsebool -P httpd_can_network_connect_db on   # -P keeps it after reboot
```

**3. A service uses a non-standard port.**

```bash
sudo semanage port -a -t http_port_t -p tcp 8088
```

When you are not sure, ask SELinux what it blocked and why:

```bash
sudo ausearch -m AVC -ts recent | audit2why
```

(`semanage` and `audit2why` come from the `policycoreutils-python-utils` package.)

## AppArmor (Ubuntu)

Ubuntu uses **AppArmor**, which confines programs by file path rather than by label. Profiles live in `/etc/apparmor.d/`. `sudo aa-status` shows which programs are confined. If a confined service is refused access to a new path, the denial appears in the kernel log (`sudo journalctl -k | grep DENIED`), and the fix is usually a small addition to that program's local profile.

## Checking yourself

A quick self-audit you can run any time:

```bash
sudo ss -tulpn                                   # anything listening that should not be?
sudo lastlog | grep -v "Never"                   # who has ever logged in
sudo last -n 20                                  # recent logins
sudo grep -h "Accepted" /var/log/secure /var/log/auth.log 2>/dev/null | tail   # successful SSH logins
sudo find /var/www -perm -o+w -type f            # files anyone can write: there should be none
```

## Try it

On AlmaLinux: create `/srv/sites/demo/index.html` in your home folder, `mv` it to `/srv/sites/demo/`, point an Nginx site at it, and watch it fail with 403. Find the denial with `ausearch`, fix it with `semanage fcontext` and `restorecon`, and confirm the page loads, all with SELinux still enforcing.
