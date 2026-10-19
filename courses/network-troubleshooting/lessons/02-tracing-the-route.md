## How a router decides where a packet goes

Every router keeps a **routing table**: a list of networks and, for each one, where to send packets for it.

```text
C     172.16.43.172/30 is directly connected, Gi0/0
S     10.43.1.0/24 [1/0] via 172.16.43.173, Gi0/0
S     192.168.42.0/24 [1/0] via 172.16.43.178, Gi0/1
S*    0.0.0.0/0 [1/0] via 172.16.43.173, Gi0/0
```

- **C** (connected): the router has an interface on that network and delivers directly.
- **S** (static): "for this network, send to the **next hop** at this address".
- **S\*** `0.0.0.0/0`: the **default route**, used when nothing more specific matches.

When several routes match, the **longest prefix wins**: a `/24` beats a `/16`, which beats the default `/0`. If nothing matches and there is no default route, the router drops the packet and replies **Destination net unreachable**.

## TTL: why packets cannot loop for ever

Every IP packet carries a **time to live** (TTL). Each router takes one off. When it reaches zero the router throws the packet away and sends back **Time exceeded** ("TTL expired in transit"). This stops a packet circling for ever when two routers point at each other.

## How tracert works

`tracert` (Windows) or `traceroute` (Linux, macOS) uses TTL on purpose:

1. It sends a packet with TTL 1. The first router throws it away and replies: that is hop 1.
2. TTL 2: the second router replies: hop 2. And so on.
3. When the destination itself replies, the trace is complete.

```text
  1     3 ms    4 ms    3 ms  r1.school.lan [10.43.1.1]
  2     5 ms    6 ms    5 ms  r2.school.lan [172.16.43.174]
  3     7 ms    8 ms    7 ms  r3.school.lan [172.16.43.178]
  4     9 ms   10 ms    9 ms  results.school.lan [192.168.42.21]
```

## Reading a broken trace

| What you see | What it usually means |
|---|---|
| Your own address reports "Destination host unreachable" at hop 1 | Your PC cannot reach its gateway: wrong gateway address, cable or Wi-Fi |
| A router reports "Destination net unreachable" | That router has no route to the destination network |
| The same two routers repeat, hop after hop | A **routing loop**: one router's route points the wrong way |
| Hops answer, then only `* * *` | Packets may be getting through, but **replies cannot get back**: a router on the way has no route to *your* network |

## There and back

A ping only succeeds if the request reaches the target **and** the reply comes back. Return traffic uses the routing tables on the way back, which can be different. When a trace dies with `* * *`, read the tables for the route **back** to your own network too.

## Fixing it

A fix is usually one setting:

- a PC's **default gateway** should be the router's address on the PC's own network;
- a missing route is added: `ip route 192.168.42.0 255.255.255.0 172.16.43.178`;
- a wrong next hop is changed to the router that is actually closer to the destination;
- a router with no way back gets a route (or default route) towards the core.
