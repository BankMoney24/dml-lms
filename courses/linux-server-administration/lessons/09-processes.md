Every running program is a **process** with a number (the PID), an owner, and a share of CPU and memory. When a server feels slow or a service misbehaves, you look at processes.

## Seeing what runs

```bash
ps aux                    # every process: user, PID, CPU %, memory %, command
ps aux | grep [n]ginx     # find one (the brackets stop grep matching itself)
pgrep -a php-fpm          # PIDs and command lines by name
top                       # live view; press q to quit, M to sort by memory, P by CPU
htop                      # friendlier live view, if installed
```

## How busy is the server?

```bash
uptime
```

```text
 10:42:11 up 12 days,  3:05,  1 user,  load average: 0.42, 0.55, 0.61
```

The **load average** is the number of processes running or waiting, averaged over 1, 5 and 15 minutes. Compare it with the number of CPU cores (`nproc`). On a 2-core server, a load of 2 means fully busy; a steady 6 means work is queuing.

```bash
free -h       # memory and swap in human units
```

Look at the **available** column, not "free": Linux uses spare memory as disk cache and gives it back when programs need it. Heavy, constant **swap** use means the server needs more memory or a program is using too much.

When memory runs out completely, the kernel's **OOM killer** stops a process to save the system. If a database or PHP vanishes without explanation, check:

```bash
sudo journalctl -k | grep -i "out of memory"
```

## Stopping processes: signals

You stop a process by sending it a **signal**:

| Signal | Number | Meaning |
|---|---|---|
| `TERM` | 15 | "Please finish up and exit." The default. Lets the program save and clean up. |
| `HUP` | 1 | Many services reload their configuration on HUP. |
| `KILL` | 9 | Stop immediately. The program gets no chance to clean up. |

```bash
kill 4321              # sends TERM to PID 4321
kill -HUP 4321         # ask it to reload
kill -9 4321           # last resort
pkill -u tobi          # TERM every process of user tobi
```

> **Warning:** Use `kill -9` only when `TERM` has failed. Killing a database with `-9` can leave half-written data. For services, prefer `systemctl stop` (next lesson), which does the right thing.

## Priorities

`nice` starts a program with lower CPU priority, so a backup or compression job does not slow down your website:

```bash
nice -n 10 tar czf /backup/site.tar.gz /var/www/site
```

## Keeping jobs alive when you disconnect

A command started in an SSH session normally dies when the session drops. For long jobs, run them inside `tmux`:

```bash
sudo dnf install tmux    # or: sudo apt install tmux
tmux new -s work         # start a session called work
# ... run your long command ...
# press Ctrl+B then D to detach; you can now log out safely
tmux attach -t work      # come back later
```

## Try it

1. Run `top`, press `M`, and note which process uses the most memory.
2. Start `sleep 600` in one SSH session. In a second session, find its PID with `pgrep -a sleep` and stop it with `kill`.
3. Work out whether your server's load average is high for its number of cores.
