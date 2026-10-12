SSH (Secure Shell) is how you reach a server's command line over the network. Everything you type and everything the server sends back is encrypted.

## Connecting

From a terminal on your own computer (Terminal on macOS and Linux, PowerShell or Windows Terminal on Windows 10 and 11, which include OpenSSH):

```bash
ssh ada@203.0.113.10
```

`ada` is your user name on the server and `203.0.113.10` is the server's address. A domain name works too: `ssh ada@lab.example.org`. If the server listens on a port other than 22, add `-p`:

```bash
ssh -p 2222 ada@203.0.113.10
```

## The host key question

The first time you connect, SSH shows something like this:

```text
The authenticity of host '203.0.113.10' can't be established.
ED25519 key fingerprint is SHA256:Qm8pXn0r3s1Z2kq0n9VvB0t7o8y1ZcQ0aJk3dXfP2w4.
Are you sure you want to continue connecting (yes/no/[fingerprint])?
```

The server is proving its identity with a key. Your computer has never seen this key, so it asks you. On a real server, compare the fingerprint with the one your provider or administrator gives you, then type `yes`. SSH remembers it in `~/.ssh/known_hosts`.

> **Warning:** If SSH later says **REMOTE HOST IDENTIFICATION HAS CHANGED**, stop. Either the server was reinstalled, or someone is intercepting your connection. Check with whoever runs the server before you continue.

## Reading the prompt

After you log in you see a prompt such as:

```text
[ada@lab ~]$
```

It tells you **who** you are (`ada`), **which machine** (`lab`) and **where** you are (`~`, your home folder). A `$` means an ordinary user; a `#` means you are `root`, the all-powerful administrator account. Treat a `#` prompt with extra care.

## Your first commands

Try each of these and read what comes back:

```bash
whoami              # which user am I?
hostname            # which machine is this?
cat /etc/os-release # which distribution and version?
uptime              # how long it has run, and how busy it is
date                # the server's clock and time zone
```

Commands are case-sensitive: `Whoami` will fail. Anything after `#` on a line is a comment and is ignored.

## Getting help

Almost every command explains itself:

```bash
ls --help     # short summary
man ls        # full manual; press q to quit, / to search
```

## Moving faster

- **Tab** completes commands and file names. Type `cat /etc/os-` and press Tab.
- **Up arrow** brings back earlier commands; `history` lists them.
- **Ctrl+C** stops the command that is running.
- **Ctrl+L** or `clear` clears the screen.

## Leaving

Type `exit` or press Ctrl+D. The connection closes; the server keeps running.

> **Tip:** If your connection is unreliable, a dropped SSH session can kill a long job. Learn `tmux` later in the course for that; for now, keep long operations short.
