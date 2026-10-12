Most server work is reading, editing, copying and searching text files: configuration, logs, scripts. This lesson gives you the core tools.

## Creating, copying, moving, removing

```bash
mkdir -p ~/practice/notes        # -p creates parent folders too, no error if they exist
touch ~/practice/notes/todo.txt  # create an empty file
cp todo.txt todo.bak             # copy a file
cp -a /etc/skel ~/skel-copy      # copy a folder, keeping permissions and times
mv todo.bak old-todo.txt         # rename (or move) a file
rm old-todo.txt                  # delete a file
rm -r ~/skel-copy                # delete a folder and everything in it
```

> **Warning:** Linux has no recycle bin. `rm` is permanent. Before any `rm -r`, run `ls` on the same path to see exactly what you are about to delete, and never type `rm -rf /` or `rm -rf *` as root.

## Editing files with nano

`nano` is the friendliest editor on a server:

```bash
nano ~/practice/notes/todo.txt
```

Type normally. **Ctrl+O** then Enter saves, **Ctrl+X** exits, **Ctrl+W** searches. The shortcuts are listed at the bottom of the screen. Many administrators use `vim`; it is worth learning later, but nano is enough for this course.

> **Tip:** Before editing an important configuration file, keep a copy: `sudo cp -a /etc/ssh/sshd_config /etc/ssh/sshd_config.bak-$(date +%F)`. The `$(date +%F)` part inserts today's date, like `2026-10-12`.

## Searching inside files with grep

```bash
grep "Listen" /etc/httpd/conf/httpd.conf    # lines containing Listen
grep -i "error" /var/log/messages          # ignore case
grep -rn "DocumentRoot" /etc/httpd/        # search a folder recursively, show line numbers
grep -v "^#" /etc/ssh/sshd_config | grep -v "^$"   # hide comments and blank lines
```

## Pipes and redirection

The `|` (pipe) sends the output of one command into the next. This is how small tools combine into powerful ones:

```bash
ps aux | grep nginx                     # find a process
cat /var/log/secure | grep "Failed" | wc -l   # count failed logins
du -sh /var/* 2>/dev/null | sort -h     # folder sizes, smallest to largest
```

Redirection sends output to a file:

| Symbol | Meaning |
|---|---|
| `>` | write output to a file, **replacing** it |
| `>>` | add output to the end of a file |
| `2>` | write error messages to a file |
| `2>&1` | send errors to the same place as normal output |
| `< file` | read input from a file |

```bash
date >> ~/practice/notes/log.txt
./backup.sh > /tmp/backup.log 2>&1
```

> **Warning:** `>` silently replaces the file. Typing `>` when you meant `>>` on a configuration file empties it. Make the backup copy first.

## Watching a log live

```bash
sudo tail -f /var/log/nginx/access.log
```

`-f` (follow) keeps printing new lines as they arrive. Press Ctrl+C to stop. This is the fastest way to see what a server is doing while you test it from a browser.

## Counting and sorting

```bash
wc -l /etc/passwd                 # number of lines (here: number of accounts)
sort names.txt | uniq -c | sort -rn | head   # most common lines first
cut -d: -f1 /etc/passwd           # first field of a colon-separated file
```

## Try it

Find the five IP addresses that requested the most pages from your web server (once you have one in module 6):

```bash
sudo awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head -5
```

Read it left to right: take the first column of each line, sort, count duplicates, sort by count, show the top five.
