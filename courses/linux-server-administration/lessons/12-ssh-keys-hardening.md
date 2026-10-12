Every server on the internet receives password-guessing attempts within minutes of going online. Key-based login, with passwords and direct root login switched off, stops almost all of them.

## How SSH keys work

A key pair has two halves. The **private key** stays on your computer and never leaves it. The **public key** is copied to every server you want to log in to. When you connect, the server checks that you hold the private key matching a public key it trusts, without the private key ever being sent.

## Create a key (on your own computer)

```bash
ssh-keygen -t ed25519 -C "ada laptop"
```

Accept the default location (`~/.ssh/id_ed25519`) and **set a passphrase**: if your laptop is stolen, the passphrase protects the key. An agent (`ssh-agent`, built into macOS and most desktops) remembers it so you do not type it every time.

## Install the public key on the server

```bash
ssh-copy-id ada@203.0.113.10
```

On Windows, where `ssh-copy-id` is missing, copy the contents of `id_ed25519.pub` into the server's `~/.ssh/authorized_keys` yourself. Either way, the permissions must be strict, or SSH ignores the file:

```bash
chmod 700 ~/.ssh
chmod 600 ~/.ssh/authorized_keys
```

Now `ssh ada@203.0.113.10` should log you in without asking for the account password.

## A config file saves typing

On your computer, `~/.ssh/config`:

```text
Host lab
    HostName 203.0.113.10
    User ada
    IdentityFile ~/.ssh/id_ed25519
```

Now `ssh lab` is enough.

## Harden the SSH server

> **Warning:** Keep your current SSH session open while you change SSH settings, and test with a **second** session before you close the first. If you make a mistake, the open session is your way back in.

Rather than editing the main file, add your own file under `/etc/ssh/sshd_config.d/` (both AlmaLinux 9 and Ubuntu 22.04+ include that folder). Files there are read in name order and, for most settings, **the first value read wins**, so give yours a low number:

```bash
sudo nano /etc/ssh/sshd_config.d/00-hardening.conf
```

```text
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
AllowGroups wheel sshusers
```

Use the admin group of your system (`wheel` on AlmaLinux, `sudo` on Ubuntu) in `AllowGroups`, and make sure **your own account** is in one of the listed groups, or you will lock yourself out.

Check the syntax and the settings that will actually apply, then reload:

```bash
sudo sshd -t                                   # no output means no syntax errors
sudo sshd -T | grep -Ei 'permitrootlogin|passwordauthentication|allowgroups'
sudo systemctl reload sshd                     # Ubuntu: sudo systemctl reload ssh
```

Now, **from a new terminal**, check that key login still works and that password login is refused:

```bash
ssh lab                                        # should work
ssh -o PubkeyAuthentication=no ada@203.0.113.10   # should fail: Permission denied (publickey)
```

## Slowing attackers further: fail2ban

`fail2ban` watches the logs and temporarily blocks addresses that keep failing to log in:

```bash
sudo dnf install fail2ban      # AlmaLinux (needs EPEL)
sudo apt install fail2ban      # Ubuntu
```

Create `/etc/fail2ban/jail.local`:

```ini
[sshd]
enabled = true
maxretry = 5
bantime = 1h
```

```bash
sudo systemctl enable --now fail2ban
sudo fail2ban-client status sshd
```

## What about changing the port?

Moving SSH from port 22 to another port reduces log noise but adds little real security; keys and disabled passwords do the real work. If you do change it, open the new port in the firewall **before** reloading SSH, and on AlmaLinux tell SELinux about it: `sudo semanage port -a -t ssh_port_t -p tcp 2222`.
