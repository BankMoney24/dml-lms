## How a router decides

A router keeps a **routing table**: a list of networks and where to send packets for each.

- **Connected** routes appear by themselves for every interface that is up and has an address.
- **Static** routes are added by hand: "for this network, send to this next router".

For each packet, the router finds every route whose network contains the destination and uses the one with the **longest prefix** (the most specific). If no route matches, the packet is dropped and the sender is told "destination net unreachable".

## Two routers, two missing routes

In a network like this, each router knows only the networks it touches:

```text
LAN A 192.168.10.0/24 ── R1 ══ 172.16.0.0/30 ══ R2 ── LAN B 10.5.5.0/24
```

| Router | Knows by itself | Must be told |
|---|---|---|
| R1 | LAN A, the link | LAN B is reached through R2 (172.16.0.2) |
| R2 | the link, LAN B | LAN A is reached through R1 (172.16.0.1) |

On a Cisco-style command line:

```text
R1(config)# ip route 10.5.5.0 255.255.255.0 172.16.0.2
R2(config)# ip route 192.168.10.0 255.255.255.0 172.16.0.1
```

The **next hop** is always the other router's address on a network you share, here the link. It is not the far side of that router.

## The way back

A ping needs two journeys: the request there **and the reply back**. If R1 knows the way to LAN B but R2 does not know the way back to LAN A, the request arrives at the server and the reply is lost. The PC says "Request timed out", which looks exactly like a problem on the way there.

> **Tip:** When a ping times out, check routes in **both** directions.

## Default routes

A route to `0.0.0.0 0.0.0.0` matches every address, so it is used only when nothing more specific matches. Small sites often give their edge router a default route towards the internet provider instead of listing every network in the world.

## Test as you go

1. Ping your own gateway.
2. Ping the router's far interface.
3. Ping the other router.
4. Ping the server.

The first test that fails tells you where to look. In the network builder, **Simulation** shows each step a packet takes, and where it stops.
