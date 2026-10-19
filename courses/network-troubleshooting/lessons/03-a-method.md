## Work from the bottom up

Most network faults are found faster with the same few steps every time. Start close to you and move outwards; stop at the first step that fails.

1. **Is my own setup right?** `ipconfig` (Windows) or `ip addr` and `ip route` (Linux).
   - Address in the right network? Mask right? Gateway on the **same** network as you?
   - An address starting `169.254.` means DHCP gave you nothing.
2. **Can I reach my gateway?** `ping` the gateway address. `arp -a` should then list its MAC address.
3. **Can I reach beyond it by address?** `ping` a known address on another network.
4. **Where does it stop?** `tracert` that address, then read the routing table of the last router that answered (and the next one).
5. **Do names work?** `nslookup name`. If addresses work but names do not, the problem is DNS, not routing.
6. **Is the service there?** The server may be reachable but the program on it (the web server, port 80 or 443) may not be running.

## Write down what you find

For each test, note the **command**, what you **expected** and what you **got**. A good fault report says:

> PC1 (10.43.1.90) cannot open results.school.lan. Ping to the gateway works. Tracert stops at R2, which reports *Destination net unreachable*. R2's routing table has no route to 192.168.42.0/24. Suggested fix: on R2, add a route to 192.168.42.0/24 via 172.16.43.178 (R3).

## Common tools

| Task | Windows | Linux / macOS |
|---|---|---|
| Your addresses | `ipconfig /all` | `ip addr`, `ip route` |
| Reach a host | `ping host` | `ping -c 4 host` |
| The path | `tracert host` | `traceroute host` |
| Names | `nslookup name` | `dig name` or `nslookup name` |
| Neighbours | `arp -a` | `ip neigh` |
| Open connections | `netstat -an` | `ss -tan` |

> Only test networks you are responsible for or have permission to test.
