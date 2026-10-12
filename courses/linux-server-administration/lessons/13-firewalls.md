A firewall decides which network connections may reach the server. The rule for a server is simple: **block everything coming in, then allow only what the server is for.**

> **Warning:** Always allow SSH **before** you switch a firewall on or change its default. Locking yourself out of a remote server can mean a support ticket, or a reinstall.

## firewalld (AlmaLinux, RHEL family)

firewalld is installed and running by default on AlmaLinux. It groups rules into **zones**; the network interface of most servers is in the `public` zone.

```bash
sudo firewall-cmd --state
sudo firewall-cmd --list-all                 # what the active zone allows
```

```text
public (active)
  interfaces: eth0
  services: cockpit dhcpv6-client ssh
  ports:
```

Allow web traffic, and remove what you do not use:

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --remove-service=cockpit
sudo firewall-cmd --permanent --add-port=8088/tcp    # a port that has no named service
sudo firewall-cmd --reload
sudo firewall-cmd --list-all
```

Changes made **with** `--permanent` are saved but only take effect after `--reload`. Changes made **without** it take effect at once but vanish at the next reload or reboot. Using `--permanent` and then `--reload` avoids confusion.

To allow something from one address only, use a rich rule:

```bash
sudo firewall-cmd --permanent --add-rich-rule='rule family="ipv4" source address="198.51.100.7" port port="3306" protocol="tcp" accept'
```

## ufw (Ubuntu)

ufw ("uncomplicated firewall") is installed on Ubuntu but **off** by default.

```bash
sudo ufw allow OpenSSH          # first!
sudo ufw allow 'Nginx Full'     # ports 80 and 443 (or: sudo ufw allow 80,443/tcp)
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw enable
sudo ufw status verbose
```

```bash
sudo ufw allow from 198.51.100.7 to any port 3306 proto tcp   # one address only
sudo ufw status numbered
sudo ufw delete 3                                             # remove rule number 3
```

## Layers of protection

Many providers also offer a **cloud firewall** in their control panel, in front of the server. If a port seems open in firewalld but still cannot be reached, check there too. Remember also the listening address from the networking lesson: a database bound to `127.0.0.1` cannot be reached from outside even if the firewall were wide open. Use both layers.

## Check from the outside

Rules are only proven from another machine. From your own computer:

```bash
nc -zv 203.0.113.10 22      # should succeed
nc -zv 203.0.113.10 3306    # should fail or time out
```

## Try it

1. List the current firewall rules.
2. Allow HTTP and HTTPS, and remove any service you do not need.
3. From your own computer, prove that port 80 is open and port 3306 is closed.
