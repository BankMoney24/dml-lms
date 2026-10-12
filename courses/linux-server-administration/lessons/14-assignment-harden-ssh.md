## The task

Make your practice server safe to leave on the internet. When you are finished:

1. You log in with an **SSH key** protected by a passphrase.
2. **Password login is refused** for every account.
3. **root cannot log in** over SSH.
4. Only members of your admin group may log in over SSH.
5. The **firewall** allows only SSH, HTTP and HTTPS from the internet; everything else coming in is blocked.
6. **fail2ban** (or an equivalent) protects SSH.

Work through the lessons in this module. Keep a second SSH session open while you change SSH settings.

## What to submit

Paste each item in its own code block:

1. The contents of the settings file you added in `/etc/ssh/sshd_config.d/`.
2. The output of:
   `sudo sshd -T | grep -Ei 'permitrootlogin|passwordauthentication|kbdinteractive|pubkeyauthentication|allowgroups'`
3. The output of `sudo firewall-cmd --list-all` **or** `sudo ufw status verbose`.
4. The output of `sudo fail2ban-client status sshd`.
5. From your **own computer**: the result of trying to log in with a password, `ssh -o PubkeyAuthentication=no youruser@yourserver`, showing it is refused.
6. A short paragraph: what would you do if you lost your laptop with the private key on it?

**Do not** paste your private key, passwords, or your server's real IP address if you do not want it shared; replace the address with `203.0.113.10`.

## How it will be judged

| Criterion | What your teacher looks for |
|---|---|
| Keys only | `passwordauthentication no` and `kbdinteractiveauthentication no` in the effective settings |
| No root login | `permitrootlogin no` |
| Restricted users | `AllowGroups` (or `AllowUsers`) set sensibly, and you still got in |
| Firewall | Default deny incoming; only SSH, HTTP, HTTPS allowed |
| Brute-force protection | fail2ban jail active for SSH |
| Judgement | A sensible plan for a lost key: remove that public key from `authorized_keys` everywhere, issue a new key |
