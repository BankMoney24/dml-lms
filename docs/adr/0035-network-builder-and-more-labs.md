# ADR 0035: Network builder, more labs, Packet Tracer files

Date: 2026-10-27 · Status: accepted

## Context

The school asked for "Packet Tracer and more labs". Cisco Packet Tracer is Cisco's own free desktop program (Windows, macOS, Ubuntu; a Cisco Networking Academy account is needed to download it). It cannot be embedded in a web page or redistributed, and many learners here only have a phone. So the platform needs its own browser-based network practice, and a way to use Packet Tracer alongside it for those who can install it.

## Decision

### Network builder

* A workspace in the browser (`public/assets/labs/network-builder.js`), in the style of Packet Tracer: add PCs, laptops, servers, switches and routers; cable ports (or "quick cable"); set host addresses, masks and gateways; set router interfaces, `no shutdown` and static routes from forms or a Cisco-style command line (`enable`, `configure terminal`, `interface`, `ip address`, `no shutdown`, `ip route`, `show ip interface brief`, `show ip route`, `show running-config`, `ping`, `traceroute`); hosts have `ipconfig`, `ping`, `tracert`, `arp -a`.
* **Test it:** ping from any device, "ping the server from every PC", and a step-by-step **simulation** that lists every ARP and ICMP event and animates the packet along the cables (no motion when the person prefers reduced motion).
* Keyboard and screen reader use: every device is a button with a spoken label; arrow keys move devices; every action on the canvas (cabling, choosing a device) also exists as a form; the inspector lists all devices.
* The model (switch segments, ARP on the segment, gateways, longest-prefix routing, TTL loops, replies needing a way back) exists twice with the same rules: in the browser for the learner's own tests, and in PHP (`App\Modules\VirtualLabs\Network\Simulator`) for marking. **Marking never trusts the browser:** the submitted network is parsed and limited (`Topology`: 30 devices, 60 cables, real ports only, one cable per port, valid addresses and contiguous masks), then rebuilt and tested on the server. A parity check (11,000 pings across 400 random networks) found no difference between the two.
* Work is kept in the browser while the attempt is open, so a reload does not lose it.
* Two labs use it:
  * **Build a routed network:** from an empty workspace, two PCs, a switch, two routers on a /30 link and a server, from an addressing plan. Marked in parts: devices, router addresses, interfaces up, host settings, cables, routes both ways, and every PC pinging the server.
  * **Repair the office network:** a finished network with three of eight kinds of fault planted (wrong gateway, mask or address on a PC, unplugged cable, interface shut down, missing return route, wrong next hop, server gateway). Each fault is marked by the setting it broke, plus every PC reaching the server.

### More labs

* **Firewall rules** (Cybersecurity): turn a plain-words policy into ordered rules (first match wins, then block). Sample traffic shows the effect; marking uses traffic the learner never sees. Blocking earns marks only when the matching service works, so an empty rule list scores 0.
* **DNS lookup** (Networking): the learner is the resolver, using `dig @server name type`, following referrals from the root to the authoritative server, then a CNAME into another zone, plus MX. Names use the reserved `.test` domain and addresses the documentation ranges; only the root server's address (198.41.0.4) is real.
* **Simple pendulum** (Physics, SS2): time 20 oscillations at several lengths; T² against l; g from the slope. g differs slightly per session.
* **Osmosis in potato strips** (Biology, SS2): percentage change in mass against sucrose concentration; the isotonic point differs per session.

### Packet Tracer files

* `.pkt` and `.pka` files can be attached to lessons and handed in for assignments. Their contents are scrambled, so they are accepted only when libmagic recognises nothing in them (a renamed program or archive is refused), always served as downloads, and virus-scanned like every upload. The file list explains where to get Packet Tracer.

### Courses

* New package **Networking Foundations: Build, Address and Route** (skills): 7 readings, 5 labs, 5 quizzes, and an optional Packet Tracer reading and assignment that are not needed to finish.
* **SS2 Science Practicals** gains the pendulum and osmosis (now 5 modules). Schools that imported it before keep their copy; importing again creates a new copy.

## Consequences

* The simulator models what beginners need (static routing, one broadcast domain per switch). VLANs, DHCP, NAT and dynamic routing are not modelled yet; the Packet Tracer path covers them for learners who can install it.
* As with the other simulations, a determined learner can read a session's numbers in the page source (the DNS lab's records have to be in the page for it to answer queries). Labs are practice with fresh numbers each attempt, not proctored exams; quizzes and teacher-reviewed assignments carry the weight of assessment.
