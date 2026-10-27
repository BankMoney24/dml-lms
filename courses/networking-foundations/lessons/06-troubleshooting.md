## Work from the bottom up

When "the network is down", check one layer at a time, starting with the simplest:

1. **Physical:** is a cable plugged in, and are the link lights green?
2. **Addressing:** does each device have the right address, mask and gateway?
3. **Interfaces:** are the router interfaces up (`show ip interface brief`)?
4. **Routing:** does each router have a route there **and back** (`show ip route`)?
5. **Services:** does the server answer on the right port, or is a firewall blocking it?

## One change at a time

Change one thing, then test again. If you change three things at once and it works, you will not know which one mattered. If it breaks something else, you will not know which change did it.

## Use the tools

| Tool | Tells you |
|---|---|
| `ipconfig` | A PC's own address, mask and gateway |
| `ping` | Whether a reply comes back, and roughly how far it went |
| `tracert` | Each router on the way, and where the trail stops |
| `show ip interface brief` | Which router interfaces are up and their addresses |
| `show ip route` | What a router knows and where it sends each network |
| Simulation | Every ARP and ICMP step, and the exact place a packet is dropped |

## Read the messages

- **Destination host unreachable**, from your own PC: the PC cannot reach its gateway. Look at the PC.
- **Destination net unreachable**, from a router: that router has no route. Look at its table.
- **Request timed out:** the request or the reply was lost somewhere. Check the way back too.
- **TTL expired in transit:** the packet went round in a loop between routers.

> **Tip:** Write down what you find and what you changed. A short fault report helps the next person and shows your manager what happened.
