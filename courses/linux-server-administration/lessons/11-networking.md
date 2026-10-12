A server is only useful if the right things can reach it and the wrong things cannot. This lesson shows you how to see the server's network from the inside.

## Addresses and routes

```bash
ip a          # interfaces and their IP addresses (short for ip address)
ip r          # routing table; "default via" is the gateway to the internet
```

```text
2: eth0: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500 ...
    inet 203.0.113.10/24 brd 203.0.113.255 scope global eth0
```

`lo` with `127.0.0.1` is the **loopback** interface: the machine talking to itself. Many services, such as a database, should listen only there so nothing outside can reach them.

## Which programs are listening?

```bash
sudo ss -tulpn
```

```text
Netid State  Local Address:Port  Process
tcp   LISTEN 0.0.0.0:22          users:(("sshd",pid=701))
tcp   LISTEN 0.0.0.0:80          users:(("nginx",pid=812))
tcp   LISTEN 127.0.0.1:3306      users:(("mariadbd",pid=930))
```

Read the **Local Address** column carefully:

| Address | Reachable from |
|---|---|
| `0.0.0.0` or `[::]` or `*` | every network the server is on (subject to the firewall) |
| `127.0.0.1` or `[::1]` | this server only |
| a specific IP | only through that interface |

Common ports: 22 SSH, 80 HTTP, 443 HTTPS, 25/587 mail, 3306 MySQL/MariaDB, 5432 PostgreSQL.

## Testing connections

```bash
ping -c 4 example.org                  # is the host reachable? (some block ping)
curl -I https://example.org            # fetch only the HTTP headers
curl -v http://127.0.0.1/              # talk to your own web server, showing every step
nc -zv 203.0.113.20 3306               # is a TCP port open? (install nmap-ncat or netcat)
```

`curl` is your best friend for web problems: it shows exactly what the server answered, without a browser's caching and guesswork.

## Names: DNS

DNS turns names into addresses.

```bash
dig +short example.org A          # the IPv4 address
dig +short example.org AAAA       # IPv6
dig +short example.org MX         # mail servers
dig @1.1.1.1 example.org          # ask a specific resolver
```

The server's own resolver settings are in `/etc/resolv.conf`, and fixed local names can go in `/etc/hosts`, which is checked first.

> **Tip:** After you change a domain's DNS records, different resolvers may show old answers until the record's **TTL** expires. `dig` shows the TTL as the number before `IN`.

## The server's own name

```bash
hostnamectl                         # show
sudo hostnamectl set-hostname web1  # change
```

## Try it

1. Find your server's public IP address and its default gateway.
2. List the listening ports. For each one, say which program owns it and whether it should be reachable from the internet.
3. Use `curl -I` to fetch the headers of your own web server from the server itself.
