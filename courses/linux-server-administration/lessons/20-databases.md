Most web applications keep their data in a database server. On Linux that is very often **MySQL** or its close relative **MariaDB**. The commands in this lesson work on both.

## Install and secure

```bash
# AlmaLinux
sudo dnf install mariadb-server
sudo systemctl enable --now mariadb
# Ubuntu
sudo apt install mariadb-server     # or mysql-server
```

Then run the security script, which removes test accounts and the test database:

```bash
sudo mysql_secure_installation    # newer MariaDB also calls it mariadb-secure-installation
```

On both systems, the database `root` account is set up so that the Linux root user can log in without a password through the local socket: `sudo mysql` (or `sudo mariadb`). Keep it that way; do not give the database root account a password that applications use.

## One database and one user per application

Applications must **never** use the database root account. Create a database and a user with rights on that database only:

```sql
CREATE DATABASE schooldb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'schoolapp'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON schooldb.* TO 'schoolapp'@'localhost';
FLUSH PRIVILEGES;
```

Run those inside `sudo mysql`. Generate the password rather than inventing one: `openssl rand -base64 24`.

The `@'localhost'` part matters: this user can connect only from the server itself. If an application connects over TCP to `127.0.0.1` rather than through the socket, MySQL may treat that as a different host, so you may also need `'schoolapp'@'127.0.0.1'`.

## Keep it off the internet

Check where the server listens:

```bash
sudo ss -tlpn | grep -E '3306|mysql|mariadb'
```

It should show `127.0.0.1:3306` (or only a socket). If it shows `0.0.0.0:3306`, set this in the server configuration (`/etc/my.cnf.d/mariadb-server.cnf` on AlmaLinux, `/etc/mysql/mariadb.conf.d/50-server.cnf` or `/etc/mysql/mysql.conf.d/mysqld.cnf` on Ubuntu):

```ini
[mysqld]
bind-address = 127.0.0.1
```

and restart the service. Keep port 3306 closed in the firewall as well.

## Everyday commands

```sql
SHOW DATABASES;
USE schooldb;
SHOW TABLES;
SELECT COUNT(*) FROM users;
SHOW PROCESSLIST;     -- what is running right now
```

How big is each database?

```sql
SELECT table_schema AS db, ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS mb
FROM information_schema.tables GROUP BY table_schema ORDER BY mb DESC;
```

## Backups and restores

Never copy `/var/lib/mysql` while the server is running; the copy will be inconsistent. Use a dump (you met this in module 5):

```bash
sudo mysqldump --single-transaction --routines --triggers schooldb > schooldb.sql
sudo mysql -e "CREATE DATABASE schooldb_copy"
sudo mysql schooldb_copy < schooldb.sql
```

> **Tip:** Before an application upgrade that changes the database, take a dump first. It is the quickest way back if the upgrade goes wrong.

## Try it

1. Install MariaDB and run the security script.
2. Create a database `practice` and a user `practice_app` that can only use that database.
3. Log in as that user (`mysql -u practice_app -p practice`) and confirm `SHOW DATABASES;` does not list the others.
4. Dump the database and restore it under a new name.
