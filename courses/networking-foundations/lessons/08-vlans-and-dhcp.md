## One switch, two networks

Every port of a plain switch is in one **broadcast domain**: a broadcast from any computer (an ARP "who has …?", a DHCP "is there a server?") reaches every other port. In a school that is a problem: students' laptops and the staff office share the same network, so they can see each other directly.

A **VLAN** (virtual LAN) splits one switch into several separate switches. Each port belongs to one VLAN, and frames never cross from one VLAN to another inside the switch.

| Port type | Carries | Used for |
|---|---|---|
| **Access** | one VLAN, untagged | a PC, a printer, a server |
| **Trunk** | many VLANs, each frame tagged with its VLAN number (802.1Q) | the cable to another switch or to a router |

A trunk also has a **native VLAN** (VLAN 1 unless you change it). Frames in the native VLAN cross the trunk without a tag.

```text
SW1(config)# vlan 10
SW1(config-vlan)# name Staff
SW1(config)# interface range fa0/1 - 2
SW1(config-if-range)# switchport mode access
SW1(config-if-range)# switchport access vlan 10
SW1(config)# interface gi0/1
SW1(config-if)# switchport mode trunk
```

Check it with `show vlan brief` (which ports are in which VLAN) and `show interfaces trunk`.

## Routing between VLANs: a router on a stick

Each VLAN is its own network with its own addresses, so computers in different VLANs need a router to talk, just as two buildings would. One router port is enough: cable it to a trunk port, and give the router one **subinterface** per VLAN. Each subinterface takes the frames tagged with its VLAN and is the default gateway for that VLAN.

```text
R1(config)# interface gi0/0
R1(config-if)# no shutdown
R1(config)# interface gi0/0.10
R1(config-subif)# encapsulation dot1Q 10
R1(config-subif)# ip address 172.16.10.1 255.255.255.0
R1(config)# interface gi0/0.20
R1(config-subif)# encapsulation dot1Q 20
R1(config-subif)# ip address 172.16.20.1 255.255.255.0
```

The subinterfaces work only while the physical port (gi0/0) is up. `encapsulation dot1Q` comes before the address: the router has to know which VLAN the subinterface belongs to.

## DHCP: addresses without typing

Typing an address into every computer is slow and mistakes are easy (two computers with one address, a wrong gateway). With **DHCP**, a computer set to "obtain an address automatically" broadcasts a request when it connects, and a DHCP server answers with:

- an address from its **pool**,
- the subnet mask,
- the **default gateway** (on Cisco: `default-router`),
- often a DNS server too.

A pool is for one network. The server keeps track of what it has handed out, so no address is given twice. Addresses you set by hand (the router, servers, printers) should be outside the pool, or **excluded**:

```text
R1(config)# ip dhcp excluded-address 172.16.20.1 172.16.20.99
R1(config)# ip dhcp pool STUDENTS
R1(dhcp-config)# network 172.16.20.0 255.255.255.0
R1(dhcp-config)# default-router 172.16.20.1
```

That is the router as DHCP server. A school more often has one DHCP server for everything.

## One DHCP server for several VLANs: the relay

The DHCP request is a broadcast, and broadcasts stop at the edge of their VLAN. A server in the staff VLAN never hears a student laptop asking. The fix is a **DHCP relay** on the router: on the student VLAN's subinterface, `ip helper-address` with the server's address. The router catches the broadcast and sends it straight to the server, saying which network it came from; the server picks the pool for that network.

```text
R1(config)# interface gi0/0.20
R1(config-subif)# ip helper-address 172.16.10.250
```

For this to work the server needs a pool for the student network, and a default gateway so its answer can get back to the router.

## When a computer gets no address

On Windows, a computer that gets no DHCP answer gives itself a **169.254.x.x** address. It can only reach other computers that also failed, so seeing 169.254 in `ipconfig` means "DHCP did not work". Check, in order:

1. Is the cable plugged in, and is the switch port in the right VLAN?
2. Is there a DHCP server in that VLAN, or a relay (`ip helper-address`) on the VLAN's router interface?
3. Does the server have a pool for that network, with free addresses?
4. Can the server reach back (default gateway, routes)?

Then run `ipconfig /renew` to ask again.
