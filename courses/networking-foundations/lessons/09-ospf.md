## Why static routes stop being enough

With two routers, static routes are easy: one route each way. With three sites you need a route on every router for every network it is not connected to, and you must change them by hand whenever a network is added. Worse, a static route does not notice when its link fails. If the cable between two routers is cut, packets keep going to a next hop that no longer answers, even when another way round exists.

A **routing protocol** fixes both problems. The routers tell each other which networks they have, work out the best path to each, and work it out again when something changes. **OSPF** (Open Shortest Path First) is the one most networks use inside a school, a company or a campus.

## How OSPF works, in four steps

1. **Find neighbours.** Each router sends small *hello* messages out of its OSPF interfaces. Two routers on the same link become **neighbours** when their hellos agree: the same network and mask, and the same **area**.
2. **Share the map.** Neighbours swap descriptions of their links. Soon every router has the same map of the whole network.
3. **Work out the shortest paths.** Each router runs the shortest-path calculation on that map, from itself to every network. Each link has a **cost**; on fast links it is 1, so here the best path is the one through the fewest routers.
4. **Fill the routing table.** The best paths go in the routing table as **O** routes, next to the connected (**C**) and static (**S**) ones.

When a link fails, the routers on either side notice (the hellos stop), tell everyone, and every router works its paths out again. Traffic moves to the next best path, usually within seconds.

## Turning OSPF on: network statements and wildcards

You start OSPF with a process number (it only matters on that router) and then say which interfaces take part, with **network statements**:

```text
R1(config)# router ospf 1
R1(config-router)# network 10.20.10.0 0.0.0.255 area 0
R1(config-router)# network 10.20.200.0 0.0.0.3 area 0
R1(config-router)# network 10.20.200.8 0.0.0.3 area 0
```

The middle number is a **wildcard**, not a mask: it is the mask turned inside out. Where the mask has 255, the wildcard has 0 ("this part must match"); where the mask has 0, the wildcard has 255 ("anything here"). A /24 mask of 255.255.255.0 becomes the wildcard 0.0.0.255, and a /30 link's 255.255.255.252 becomes 0.0.0.3. Every interface whose address matches a statement runs OSPF in that statement's area, and its network is advertised to the other routers.

| Mask | Wildcard | Matches |
|---|---|---|
| 255.255.255.0 (/24) | 0.0.0.255 | one /24 network |
| 255.255.255.252 (/30) | 0.0.0.3 | one /30 link |
| 255.255.255.240 (/28) | 0.0.0.15 | a block of 16 addresses, so four /30 links at once |
| 255.255.255.255 (/32) | 0.0.0.0 | exactly one interface address |

Typing the mask where the wildcard should go is the most common mistake. In the network builder, and on many real routers, the result is that nothing matches and OSPF quietly does nothing, so always check afterwards.

Small networks use one area, **area 0** (the backbone). Larger ones split into several areas joined to area 0; that is for a later course.

## Passive interfaces

A router's LAN port faces PCs, not routers, so there is nobody there to answer its hellos. Make it **passive**: its network is still advertised to the other routers, but no hellos go out of it. This is tidier, and safer, because nobody on the LAN can pretend to be a router.

```text
R1(config-router)# passive-interface gi0/0
```

## Checking your work

| Command | What to look for |
|---|---|
| `show ip ospf neighbor` | One line per neighbour, in state FULL. In a triangle, each router has two. |
| `show ip route` | **O** routes for every network the router is not connected to, with `[110/cost] via` the neighbour's address. 110 is how much OSPF is trusted: a static route (1) wins over it. |
| `show ip ospf interface brief` | Which interfaces run OSPF, in which area, and which are passive. |
| `show ip protocols` | The network statements and passive interfaces, as the router read them. |

If two routers will not become neighbours, check both ends: is the link up, are both interfaces covered by a network statement (wildcard, not mask), is neither side passive, and are they in the same area with the same mask?

## Sharing a way out: the default route

Usually one router connects to the internet. Give that router a static **default route** (`ip route 0.0.0.0 0.0.0.0` followed by the internet provider's address), then tell OSPF to share it:

```text
R1(config-router)# default-information originate
```

The other routers learn it as `O*E2 0.0.0.0/0`: "for anything I have no better route to, go towards R1".

## In the lab

Three sites are joined in a triangle. Every address is already set, and nothing is routed, so no site can reach another. Turn on OSPF on all three routers, make the LAN ports passive, and check the neighbours and routes. When every site reaches every other, shut down one of the router-to-router links and ping again: OSPF should send the traffic the long way round. When you hand in, the server does the same to each link in turn.
