Things will break: after an update, after a change, or for no obvious reason at 2 a.m. Good administrators are not the ones who never see failures; they are the ones who find the cause quickly and calmly. This lesson gives you a method.

## The method

Work through these questions **in order**. Most problems are found by question 3.

1. **What exactly is wrong?** Reproduce it yourself. "The site is down" might mean one page, one user, one network. Get the exact error and the time it started.
2. **What changed?** An update (`dnf history`, `/var/log/apt/history.log`), a configuration edit, a deploy, a certificate renewal, a full disk. Most failures follow a change.
3. **What do the logs say?** The service's journal and its own logs, at the time of the failure.
4. **Is it running?** `systemctl status`, `ss -tulpn`. Is it listening where you think?
5. **Are the resources there?** Disk (`df -h`, `df -i`), memory (`free -h`), load (`uptime`).
6. **Can the pieces reach each other?** Firewall, DNS, `curl` from the server itself, then from outside.
7. **Is access allowed?** Permissions on every directory in the path, ownership, SELinux or AppArmor denials.

Change **one thing at a time**, and check after each change. Changing three things at once means you never learn which one mattered, and you may add a new problem.

## Worked example: 502 Bad Gateway

Users report a 502 error on a PHP site.

```bash
curl -I http://127.0.0.1 -H "Host: school.example.org"
# HTTP/1.1 502 Bad Gateway      → Nginx is up; the problem is behind it

sudo tail -n 5 /var/log/nginx/school.error.log
# connect() to unix:/run/php-fpm/www.sock failed (2: No such file or directory)

systemctl status php-fpm
# Active: failed (Result: exit-code) since 02:14

sudo journalctl -u php-fpm -n 20
# ERROR: [/etc/php-fpm.d/www.conf:38] value is NULL for a ZEND_INI_PARSER_ENTRY

sudo dnf history | head -5
# 02:10 Upgrade php-fpm …      → an update at 02:10 replaced a config file
```

Cause found: an update changed the PHP-FPM configuration and a line now has an error. Compare with the `.rpmnew` or `.rpmsave` copy the package manager left beside it (`ls /etc/php-fpm.d/`), fix line 38, then:

```bash
sudo php-fpm -t && sudo systemctl restart php-fpm
curl -I http://127.0.0.1 -H "Host: school.example.org"
# HTTP/1.1 200 OK
```

## Worked example: "No space left on device"

```bash
df -h /
# /dev/vda2  39G  39G  0  100% /
sudo du -xh --max-depth=1 / | sort -h | tail -3
# 31G /var
sudo du -xh --max-depth=1 /var | sort -h | tail -3
# 29G /var/log
sudo ls -lhS /var/log | head -3
# 28G app-debug.log
```

An application left debug logging on. Empty the file (`sudo truncate -s 0 /var/log/app-debug.log`), turn debug logging off, add a logrotate rule, and add disk space to your monitoring so it warns at 80% next time.

## Write it down

After every incident, write a few lines: what users saw, the cause, how you fixed it, and what will stop it happening again. This **incident note** is how a team gets better instead of fighting the same fire every month.

```text
2026-10-12 02:14–02:41  PHP site returned 502
Cause: php-fpm update replaced www.conf; syntax error at line 38
Fix: corrected line 38, restarted php-fpm
Prevent: run php-fpm -t in the update checklist; health check now alerts on failed services
```

## Try it

Ask a classmate (or your teacher) to break your practice server in one way without telling you how: stop a service, change a permission, fill the disk, block a port. Use the method to find and fix it, and write the incident note.
