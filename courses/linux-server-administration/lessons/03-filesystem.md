Linux stores everything in one tree of folders (directories) that starts at `/`, called the *root*. There are no drive letters. Knowing where things live is half of administering a server.

## The important directories

| Path | What lives there |
|---|---|
| `/etc` | System and service configuration files. You will edit these a lot. |
| `/var` | Data that changes: logs (`/var/log`), websites (`/var/www`), databases (`/var/lib/mysql`) |
| `/home` | Each user's home folder, e.g. `/home/ada` |
| `/root` | The root user's home folder |
| `/usr` | Installed programs and their files |
| `/tmp` | Temporary files, often cleared on reboot |
| `/opt` | Optional software installed outside the package manager |
| `/srv` | Data served by this machine; a good home for project files |

## Where am I, and what is here?

```bash
pwd          # print working directory
ls           # list files
ls -l        # long listing: permissions, owner, size, date
ls -la       # include hidden files (names starting with a dot)
ls -lh /var/log   # human-readable sizes, in another directory
```

## Moving around

```bash
cd /etc      # absolute path: starts with /
cd nginx     # relative path: from where you are now
cd ..        # up one level
cd ~         # your home folder (cd on its own does the same)
cd -         # back to the previous directory
```

An **absolute path** starts at `/` and works from anywhere. A **relative path** depends on where you are. In scripts and configuration files, always use absolute paths.

> **Note:** `.` means "this directory" and `..` means "the parent directory". You will see `./script.sh`, which runs a script in the current directory.

## Hidden files

Files whose names start with a dot, such as `.bashrc` or `.ssh`, are hidden from a plain `ls`. They are usually settings. Use `ls -a` to see them.

## Looking at files safely

```bash
cat /etc/hostname          # print a short file
less /var/log/messages     # page through a long file (q to quit, / to search)
head -n 20 /etc/passwd     # first 20 lines
tail -n 50 /var/log/secure # last 50 lines
```

On Ubuntu the main logs are `/var/log/syslog` and `/var/log/auth.log`; on AlmaLinux they are `/var/log/messages` and `/var/log/secure`. You may need `sudo` to read them.

## Finding things

```bash
find /etc -name "*.conf"              # files by name
find /var/log -mtime -1               # changed in the last day
find / -size +500M 2>/dev/null        # big files; hide permission errors
which python3                         # where a command lives
```

## Try it

1. Go to `/etc` and count how many `.conf` files are directly in it: `ls /etc/*.conf | wc -l`.
2. Find out how big `/var/log` is: `sudo du -sh /var/log`.
3. Go back to your home folder in one command.
