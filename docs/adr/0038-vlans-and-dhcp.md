# ADR 0038: VLANs, trunks, router subinterfaces and DHCP in the network builder

Date: 2026-10-28 · Status: accepted

## Context

The network builder (ADR 0035) treated every switch as one broadcast domain and every host as statically addressed. "VLANs, DHCP and dynamic routing" was the next step on the checklist. VLANs and DHCP are core to any school or office network, and to CCNA-level teaching. Dynamic routing (OSPF) is a separate, larger piece.

## Decision

### The model (server `Simulator` and browser `Sim`, kept identical)

* **Switch ports** are access ports in one VLAN (default VLAN 1) or trunks. A trunk carries its native VLAN untagged and every other VLAN tagged (802.1Q). There is no allowed-VLAN list: a trunk carries every VLAN. Ports are always up, and spanning tree is not modelled. VLANs can be named; names are only labels.
* **Router subinterfaces** (`Gi0/0.10`, up to 8) carry one VLAN tagged and have their own address. They are up whenever their physical port is up. This is enough for a router on a stick.
* **Segments** are worked out per kind of frame. A cable joins the two ends where they carry the same kind: untagged meets untagged, and VLAN 10 tagged meets VLAN 10 tagged. Inside a switch, everything in the same VLAN is joined. Two switches linked by access ports in different VLANs join those VLANs, as real switches do.
* **DHCP:**
  * A host set to DHCP asks on its segment. A server or router with an interface there answers if it has a pool for that interface's network.
  * Otherwise a router interface there with `ip helper-address` relays the request. This works only if that router can reach the server and the server can answer back. The server then uses its pool for the relaying interface's network.
  * Leases go out in device order, skipping addresses already in use, network and broadcast addresses, and the router's excluded ranges. So the same network always gets the same leases on the server and in the browser.
  * A host with no lease has no address. Its ping says why, for example "no DHCP server answers on its network" or "the pool … has no free addresses left".
* **Limits:** at most 8 subinterfaces, 4 pools and 4 excluded ranges per device, and 16 VLAN names per switch. Everything sent by the browser is checked and limited in `Topology` as before.

### Interface

* **Switch:**
  * A VLANs tab with each port's mode and VLAN, plus VLAN names.
  * A Cisco-style command line: `vlan`, `name`, `interface` and `interface range`, `switchport mode access|trunk`, `switchport access vlan` (which creates the VLAN with Cisco's message), `switchport trunk native vlan`, `show vlan brief`, `show interfaces trunk` and `show running-config`.
* **Router:**
  * A Subinterfaces tab, and a relay field on each interface.
  * A DHCP tab with pools, excluded ranges and the addresses handed out.
  * Command line: `interface Gi0/0.10`, `encapsulation dot1Q`, `ip helper-address`, `ip dhcp pool` with `network` and `default-router`, `ip dhcp excluded-address`, `show ip dhcp binding` and `show ip dhcp pool`. `show ip interface brief` and `show running-config` include all of these.
  * As on Cisco, an address on a subinterface needs `encapsulation dot1Q` first.
* **Server:** a DHCP service tab with pools: gateway, start address, mask and maximum users.
* **PCs:**
  * Static or DHCP. The lease is shown under the settings and on the canvas.
  * `ipconfig` shows the lease, the DHCP server, or a 169.254 address with the reason. `ipconfig /renew` asks again.
* **Canvas:** trunks are drawn thicker, and port labels show "v10" or "trunk".

### Lab and course

* New lab `network-vlan`, "VLANs, a router on a stick and DHCP" (Advanced):
  * One switch is shared by staff and students, and the devices are placed and cabled.
  * The learner sets up the two VLANs and the trunk, R1's subinterfaces, and DHCP pools on SRV1 with a relay for the student VLAN, then sets every PC to DHCP.
  * Marks: router on a stick 20, VLANs and trunk 25, DHCP for both VLANs (including the relay) 30, every PC reaches the server 25.
  * The VLAN numbers, networks, server address and site change with every session.
* Networking Foundations gets a seventh module, "VLANs and DHCP": a reading, the lab, and a four-question check.

## Consequences

* The JS and PHP copies were checked against each other: 25,200 random cases with VLAN, trunk, subinterface, relay, pool and exclusion changes, comparing leases, ping results and reasons, all matching. The earlier 11,228 routing cases still match.
* Existing saved networks keep working: a switch with no port settings behaves exactly as before.
* Still to do: dynamic routing (OSPF), allowed-VLAN lists, spanning tree, and DHCP options beyond the gateway.
