Linux is multi-user from the ground up. Every file belongs to a user and a group, and every running program acts as some user. Good administration means giving each person and each program only the access it needs.

## Who exists?

Accounts are listed in `/etc/passwd`, one per line:

```text
ada:x:1001:1001:Ada Obi:/home/ada:/bin/bash
```

The fields are: name, password placeholder (the real hash lives in `/etc/shadow`, readable only by root), user ID (UID), main group ID (GID), description, home folder, and login shell. Accounts with UIDs below 1000 are usually *system accounts* for services such as `apache` or `mysql`; they cannot log in.

```bash
id            # your UID, GID and groups
id ada        # someone else's
getent group  # all groups
```

## Creating and changing users

```bash
sudo useradd -m -s /bin/bash -c "Tobi Ade" tobi   # -m creates /home/tobi
sudo passwd tobi                                  # set a password
sudo usermod -aG developers tobi                  # add to an extra group
sudo userdel -r tobi                              # remove user and home folder
```

> **Warning:** In `usermod -aG`, the `a` (append) matters. `usermod -G developers tobi` without `-a` **removes** Tobi from every other extra group, including the admin group.

On Ubuntu, `adduser tobi` is an interactive alternative that asks questions as it goes.

## Groups

Groups let several users share access to the same files.

```bash
sudo groupadd webteam
sudo usermod -aG webteam ada
```

A user's new group membership takes effect at their **next login**. Log out and back in, then check with `id`.

## Root and sudo

`root` (UID 0) can do anything, including destroy the system. Instead of logging in as root, administrators use **sudo** to run a single command with root's power:

```bash
sudo dnf update        # AlmaLinux
sudo apt update        # Ubuntu
sudo -i                # a root shell, for a series of admin commands; exit when done
```

sudo asks for **your own** password, not root's, and records every command in the system log, so you can see later who did what.

Who may use sudo is decided by group membership:

| Family | Admin group |
|---|---|
| AlmaLinux / RHEL | `wheel` |
| Ubuntu / Debian | `sudo` |

```bash
sudo usermod -aG wheel ada    # AlmaLinux
sudo usermod -aG sudo ada     # Ubuntu
```

Finer rules live in `/etc/sudoers` and files under `/etc/sudoers.d/`. Always edit them with `sudo visudo` (or `sudo visudo -f /etc/sudoers.d/deploy`), which checks the syntax before saving. A broken sudoers file can stop everyone from using sudo.

For example, this rule lets the `deploy` user restart one service and nothing else:

```text
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl restart myapp
```

## Service accounts

Programs should run as their own unprivileged user, never as root. If a web application is broken into, the attacker gets only what that user can touch.

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin myapp
```

`nologin` means nobody can log in as this account; it exists only to own files and run a process.

## Try it

1. Create a user `tobi` with a home folder.
2. Create a group `webteam` and add both yourself and `tobi` to it.
3. Run `id tobi` and confirm the group is listed.
