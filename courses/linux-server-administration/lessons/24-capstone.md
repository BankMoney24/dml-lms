## The project

Build a small but properly run production server, from a fresh install, using everything in this course. Imagine it will host your school's website and that you will hand it over to another administrator, so it must be secure, recoverable and documented.

## Requirements

**Access and users**

1. Your own admin account with SSH key login; password login and root login over SSH are off.
2. A separate, unprivileged system user that owns and runs the website.

**Network**

3. The firewall allows only SSH, HTTP and HTTPS.
4. The database listens on `127.0.0.1` only.

**The website**

5. A website served by Nginx or Apache with its own server block / virtual host and its own log files. It may be a static page or a simple PHP page that reads one row from the database.
6. HTTPS with a Let's Encrypt certificate, HTTP redirecting to HTTPS, and a passing `certbot renew --dry-run`. If you have no domain, use a self-signed certificate and explain what you would change for a real domain.
7. A database and a database user with rights on that database only.

**Operations**

8. A nightly backup of the website files, the database (as a dump) and `/etc`, kept for 7 days, copied off the server.
9. A **tested restore**: restore the backup into a separate folder and database and show that it is complete.
10. A health check that reports disk space, failed services, the website's HTTP status and backup age, run on a schedule.
11. Automatic security updates switched on.
12. On AlmaLinux: SELinux **enforcing** throughout.

## What to submit

1. **A runbook** (in your answer, using headings): how the server is built, where each piece lives, how to deploy a change, how to restore from backup, and what the health check watches. Write it for the person who takes over from you.
2. **Evidence**, in code blocks:
   - `sudo sshd -T | grep -Ei 'permitrootlogin|passwordauthentication|allowgroups'`
   - your firewall listing
   - `sudo ss -tulpn`
   - `curl -I http://yourdomain` and `curl -I https://yourdomain`
   - `sudo certbot certificates` (or your self-signed certificate's dates)
   - your backup script, its schedule, and `ls -lh` of the backup folder
   - the commands and output of your restore test
   - your health-check script and its schedule
   - `getenforce` (AlmaLinux)
3. **A link** to a repository or shared document with your scripts, if you have one.
4. **Reflection** (one paragraph): the hardest problem you hit and how you found its cause.

Remove passwords, private keys and anything else secret before submitting.

## How it will be judged

| Area | Weight | What earns full marks |
|---|---|---|
| Security | 30% | Keys only, no root login, minimal open ports, DB local only, least-privilege users, SELinux enforcing |
| Website and HTTPS | 20% | Correct virtual host, valid certificate, redirect, renewal proven |
| Backups and restore | 25% | All three parts backed up, retention, off-server copy, restore actually tested |
| Monitoring and updates | 10% | Health check runs on schedule; automatic security updates on |
| Runbook and reflection | 15% | Someone else could run the server from your document |

Your teacher will approve the project when every requirement is met, or ask for specific changes. This project, together with the final exam, completes the course.
