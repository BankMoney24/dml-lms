## Names for people, numbers for networks

People remember `portal.myschool.edu.ng`; networks deliver to addresses like `203.0.113.40`. The **Domain Name System (DNS)** turns one into the other.

Names are read from right to left, like an address on an envelope:

```text
portal . myschool . edu . ng .
 host     school    sector  country  root
```

Each level is a **zone** run by someone: the root by the root server operators, `ng` by the country's registry, `edu.ng` by whoever runs it, and `myschool.edu.ng` by the school or its host.

## Following referrals

Your computer asks a **resolver** (often run by your internet provider), and the resolver does the legwork:

1. It asks a **root server**: "where is portal.myschool.edu.ng?" The root does not know, but it refers the resolver to the servers for `ng`.
2. It asks an `ng` server, which refers it to the servers for `edu.ng`.
3. It asks an `edu.ng` server, which refers it to the school's own name servers.
4. It asks the school's server, which answers **authoritatively**: it holds the zone.

A referral puts the next servers' names in the **AUTHORITY** section and, where it can, their addresses in the **ADDITIONAL** section (called glue).

## Record types you will meet

| Type | Meaning | Example |
|---|---|---|
| A | The IPv4 address of a name | `www  A  203.0.113.10` |
| NS | A name server for a zone | `myschool.edu.ng  NS  ns1.host.net` |
| CNAME | This name is an alias for another name | `portal  CNAME  myschool.lms-host.net` |
| MX | Where email for the domain goes, with a preference number | `MX 10 mail1.myschool.edu.ng` |

When the answer is a **CNAME**, the resolver starts again with the new name, often in a completely different zone.

For **MX**, the **lowest** preference number is tried first; higher numbers are back-ups.

## Caching and TTL

Every record carries a **TTL** (time to live) in seconds. Resolvers keep answers that long, so most lookups never reach the root at all. A short TTL (60 s) lets a site move quickly; a long one (86400 s, a day) saves traffic.

> **Note:** The DNS lab uses the reserved `.test` domain and documentation addresses, so it cannot point at a real school by accident. Only the root server's address (`198.41.0.4`, a.root-servers.net) is real.
