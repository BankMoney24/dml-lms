## What happens when you open a website

Typing `learn.example.org` into a browser and pressing Enter sets off a short, very regular conversation. A packet capture tool such as Wireshark records every packet of it. Once you know the pattern, a capture stops looking like noise.

Four protocols do the work, in this order:

| Step | Protocol | Question it answers | Runs over |
|---|---|---|---|
| 1 | **ARP** | What is the MAC address of my default gateway? | Ethernet only, no IP |
| 2 | **DNS** | What IP address does this name have? | UDP, port 53 |
| 3 | **TCP** | Can we open a reliable connection? | IP, server port 80 or 443 |
| 4 | **HTTP** | Please send me this page. | Inside the TCP connection |

## 1. ARP: finding the gateway

Your laptop knows its gateway's **IP address** (from `ipconfig`), but on the local network frames are delivered by **MAC address**. So it shouts to everyone:

```text
Who has 192.168.16.1? Tell 192.168.16.70        (to Broadcast, ff:ff:ff:ff:ff:ff)
192.168.16.1 is at a4:5e:60:b5:3a:90             (the reply, sent only to you)
```

The **reply** carries the gateway's MAC address. Your laptop remembers it (see `arp -a`) and uses it for every packet leaving the local network.

## 2. DNS: turning a name into an address

The laptop sends a **query** to its DNS server on **UDP port 53** and gets a **response**. Both carry the same **transaction ID**, so you can pair them even when other lookups are mixed in:

```text
Standard query 0x9f7a A results.example.net
Standard query response 0x9f7a A results.example.net A 203.0.113.23
```

The address after the last `A` is the answer. The source of the response is the DNS server.

## 3. TCP: the three-way handshake

Before any web data moves, TCP sets up a connection in three packets:

1. **SYN** from the client, from a random high **source port** (for example 52099) to port **80**.
2. **SYN, ACK** from the server: "I heard you, and here is my starting number".
3. **ACK** from the client: the handshake is **complete**.

The client's port stays the same for the whole connection, so it is a good way to pick one conversation out of many.

## 4. HTTP: the request and the answer

The client sends a request such as `GET /timetable HTTP/1.1` with a `Host:` line. The server answers with a **status line**:

| Code | Meaning |
|---|---|
| 200 OK | Here is the page |
| 301 Moved Permanently | It is somewhere else; look at the `Location:` header |
| 403 Forbidden | You are not allowed to see it |
| 404 Not Found | There is no such page |
| 500 Internal Server Error | The server itself went wrong |

Finally each side sends **FIN, ACK** and the other acknowledges: the connection closes politely.

## Reading a capture

- Use the **display filter** to cut the noise: `dns`, `tcp`, `http`, `arp`, `ip.addr==203.0.113.23`.
- Choose a packet to see its **layers**: Ethernet (MAC addresses), IP (addresses, TTL), UDP or TCP (ports, flags), then the application (DNS or HTTP).
- The **bytes** pane shows the same packet in hexadecimal. The first 6 bytes are the destination MAC, the next 6 the source MAC.

> **Plain HTTP is readable by anyone on the path.** That is why real sites use HTTPS (port 443): the handshake looks the same, but the HTTP inside is encrypted.
