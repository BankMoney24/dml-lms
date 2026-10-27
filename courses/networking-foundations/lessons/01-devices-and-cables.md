## The parts of a network

A network is computers and other devices joined so they can send each other data. Three kinds of device do most of the work in a school or office.

| Device | What it does | Works with |
|---|---|---|
| **PC, laptop, server** | Sends and receives data for people and programs | Its own IP address |
| **Switch** | Joins devices on the **same** network and passes each frame to the port where the receiver is | MAC (hardware) addresses |
| **Router** | Joins **different** networks and decides where each packet goes next | IP addresses and its routing table |

A switch makes one local network (a **LAN**). Everything plugged into it can reach everything else directly. A router sits between LANs. A packet for another network goes to the router, and the router passes it on.

## Ports and cables

Each device has **ports**. A PC usually has one network port. A small switch has 8, 24 or 48. A router has a few, each joined to a different network.

In the network builder and in real equipment, ports have names:

- `Fa0/1` is a FastEthernet port (100 Mb/s), number 1 on slot 0.
- `Gi0/0` is a GigabitEthernet port (1000 Mb/s).

A cable joins exactly two ports. One port takes one cable.

> **Tip:** Lights on a port show its state. In the network builder, a green light means the link can carry traffic. Red means the port at one end is shut down.

## A router interface is a door into a network

Each router port that is in use has its own IP address on the network it is plugged into. That address is the **default gateway** for the computers on that network: the door they use to leave it.

```text
PCs ── switch ── [Gi0/0] R1 [Gi0/1] ── [Gi0/0] R2 [Gi0/1] ── server
 LAN A             192.168.10.1  172.16.0.1  172.16.0.2  10.5.5.1   LAN B
```

R1's `Gi0/0` address is the gateway for the PCs. R2's `Gi0/1` address is the gateway for the server. The two routers share a small network of their own, the link between them.

## Shut down until you say so

On most routers an interface stays **administratively down** until someone turns it on. On the command line that is `no shutdown`; in the builder's forms it is the **Up** box. A forgotten `no shutdown` is one of the most common reasons a new network does not work.
