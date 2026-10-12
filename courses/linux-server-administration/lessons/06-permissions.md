Permissions decide who can read, change or run each file. Most "it works for root but not for the website" problems are permission problems, so this lesson is worth reading twice.

## Reading ls -l

```text
-rw-r----- 1 ada webteam 1830 Oct 12 09:14 report.txt
drwxr-x--- 2 ada webteam 4096 Oct 12 09:10 project
```

The first character is the type: `-` for a file, `d` for a directory, `l` for a link. Then three groups of three letters:

| Position | Applies to | In the example `rw-r-----` |
|---|---|---|
| 1–3 | the owner (`ada`) | read and write |
| 4–6 | the group (`webteam`) | read only |
| 7–9 | everyone else | nothing |

## What r, w and x mean

| | On a file | On a directory |
|---|---|---|
| `r` | read the contents | list the names inside |
| `w` | change the contents | create, rename and delete files inside |
| `x` | run it as a program | enter it (`cd`) and reach files inside |

Two consequences surprise people:

- To read `/srv/site/index.html`, a user needs `x` on **every** directory on the path (`/srv` and `/srv/site`) and `r` on the file.
- Deleting a file depends on `w` on the **directory**, not on the file.

## Changing permissions with chmod

Permissions are often written as numbers: read = 4, write = 2, execute = 1, added up for each of owner, group, others.

| Number | Letters | Typical use |
|---|---|---|
| `644` | `rw-r--r--` | ordinary files: owner edits, everyone reads |
| `640` | `rw-r-----` | config with secrets: owner edits, group reads, others nothing |
| `600` | `rw-------` | private keys, `.env` files only one user needs |
| `755` | `rwxr-xr-x` | directories and scripts everyone may use |
| `750` | `rwxr-x---` | directories for owner and group only |

```bash
chmod 640 /srv/app/.env
chmod u+x deploy.sh          # symbolic form: add execute for the owner (u)
chmod g+w,o-rwx shared.txt   # group may write; others lose everything
chmod -R g+rX /srv/project   # recursive; capital X adds x to directories only
```

> **Warning:** `chmod 777` ("everyone can do everything") is never the fix. It makes a file writable by every process on the server, including a compromised one. Work out *who* needs access and grant exactly that.

## Changing ownership with chown

```bash
sudo chown ada report.txt            # new owner
sudo chown ada:webteam report.txt    # owner and group
sudo chown -R apache:apache /var/www/site/uploads   # a folder the web server writes to
```

## Shared folders: the setgid bit

When several people work in one folder, new files should belong to the shared group automatically. The **setgid** bit on a directory does that:

```bash
sudo mkdir -p /srv/project
sudo chown root:webteam /srv/project
sudo chmod 2770 /srv/project      # the leading 2 is setgid
ls -ld /srv/project
```

```text
drwxrws--- 2 root webteam 4096 Oct 12 10:02 /srv/project
```

The `s` where the group's `x` would be shows setgid is on. Files created inside now belong to `webteam`, whoever creates them.

## umask: the default for new files

New files are created with permissions limited by the **umask**. A umask of `022` gives files `644` and directories `755`; `027` gives `640` and `750`. Check yours with `umask`. Services often set their own in their configuration.

## Try it

1. As yourself (not root), create `/srv/project/hello.txt` and check with `ls -l` that its group is `webteam`.
2. Look at its permissions. With the common umask `022` the group can only *read* it. Run `chmod g+w /srv/project/hello.txt` (or use `umask 002` in shells where you do shared work).
3. Log in as `tobi` and confirm Tobi can now edit the file, while a user outside the group cannot even list the folder.
