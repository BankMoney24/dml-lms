## What a firewall does

A firewall decides, packet by packet, what may reach a computer or a network. The simplest kind looks at:

- the **protocol** (TCP or UDP);
- the **source address** (where it comes from);
- the **destination port** (which service it is for: 443 for HTTPS, 22 for SSH, 3306 for MySQL, 5432 for PostgreSQL).

## Rules are read in order

A firewall's rules are checked **from the top**. The **first rule that matches decides**, and the rest are ignored. At the bottom is a **default policy**; for a server it should be "block everything else".

```text
1  allow  tcp  any               port 443     website for everyone
2  allow  tcp  192.168.20.0/24   port 22      SSH only from the admin office
3  allow  tcp  10.1.1.20/32      port 3306    database only from the app server
   (block everything else)
```

Put a broad rule too high and the narrow ones below it never run. `allow tcp any any` at the top would let everything through, whatever follows.

## Least privilege

Allow **only what is needed**, from **only who needs it**:

- `/32` means one address (`10.1.1.20/32`, just the app server).
- `/24` means a whole network (`192.168.20.0/24`, the admin office).
- `any` means anyone on the internet. Use it only for services meant for the public, like the website.

> **Warning:** Databases and remote-management services (SSH, remote desktop) should never be open to `any`. Criminals scan the internet for them all day.

## Test your rules

For each line of the policy, think of traffic that should pass and traffic that should not, and check both. The firewall lab shows sample traffic beside your rules; the marking uses traffic you do not see, so rules that only fit the samples will not pass.
