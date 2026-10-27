## An address in two parts

An IPv4 address such as `192.168.10.25` is 32 bits, written as four numbers from 0 to 255. The **subnet mask** says which part names the network and which part names the device on it.

```text
Address  192.168.10.25
Mask     255.255.255.0     (also written /24: the first 24 bits are the network)
Network  192.168.10.0
Device   .25
```

Two devices are on the **same network** when their addresses agree in every network bit. With a /24 mask, `192.168.10.25` and `192.168.10.200` are on the same network; `192.168.11.5` is not.

## Addresses you cannot give to a device

In each network, two addresses are reserved:

- the **network address**, with all device bits 0 (`192.168.10.0`);
- the **broadcast address**, with all device bits 1 (`192.168.10.255`).

A /24 network has 256 addresses, so 254 are usable. A /30 has 4 addresses and only **2 usable**. That is exactly right for a link between two routers.

| Prefix | Mask | Addresses | Usable |
|---|---|---|---|
| /24 | 255.255.255.0 | 256 | 254 |
| /28 | 255.255.255.240 | 16 | 14 |
| /30 | 255.255.255.252 | 4 | 2 |

## The default gateway

When a PC sends to an address, it first asks: **is it on my network?**

- **Yes:** it finds the receiver's hardware address with ARP ("who has 192.168.10.200?") and sends the frame straight to it.
- **No:** it sends the packet to its **default gateway**, the router's address on its own network, and lets the router deal with it.

So a PC needs three settings: its **address**, its **mask** and its **gateway**. The gateway must be on the PC's own network, or the PC cannot reach it.

> **Warning:** A mask that is too small (for example /28 instead of /24) can make the gateway look as if it is on another network. The PC then cannot leave its own network, even though its address looks right.

## Checking a PC

On Windows, `ipconfig` shows all three settings. Compare them with the plan:

```text
IPv4 Address. . . . . : 192.168.10.25
Subnet Mask . . . . . : 255.255.255.0
Default Gateway . . . : 192.168.10.1
```

Then `ping` the gateway. If the gateway answers, the PC and its LAN are fine and any problem is further on.
