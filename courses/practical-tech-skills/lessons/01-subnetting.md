## Two parts of every IPv4 address

An IPv4 address such as `192.168.10.37` is 32 bits, written as four numbers (octets) from 0 to 255. Every address has two parts:

- the **network part**, shared by every device on the same network;
- the **host part**, different for each device.

The **prefix** (written after a slash, as in `192.168.10.37/26`) says how many bits, from the left, are the network part. The **subnet mask** is the same thing written as an address: `/26` = `255.255.255.192`.

## Octets in binary

Each octet is 8 bits worth 128, 64, 32, 16, 8, 4, 2 and 1:

```text
37  = 0 0 1 0 0 1 0 1     (32 + 4 + 1)
192 = 1 1 0 0 0 0 0 0     (128 + 64)
```

## Network address, broadcast address and hosts

For `192.168.10.37/26`, only the last octet is split (26 = 8 + 8 + 8 + 2):

```text
address   37 = 00|100101
mask     192 = 11|000000
network       00|000000 = 0     → network address   192.168.10.0
broadcast     00|111111 = 63    → broadcast address 192.168.10.63
```

- **Network address**: keep the network bits, set every host bit to 0 (this is address AND mask).
- **Broadcast address**: keep the network bits, set every host bit to 1.
- **Usable hosts**: 2 to the power of the host bits, minus 2 (the network and broadcast addresses cannot be given to devices). Here 2⁶ − 2 = **62**.

## Are two devices on the same network?

Work out the network address of each. If they match, the devices can talk directly; if not, traffic has to go through a router.

`192.168.10.70/26`: 70 = 01|000110, so its network is 01|000000 = 64, giving `192.168.10.64`. That is a different network from `192.168.10.37/26`.

> **Tip:** A quick way to find the block size is 256 minus the mask's split octet: 256 − 192 = 64. Networks then start at 0, 64, 128 and 192.

## When the split is in the third octet

For `/20`, the split is in the third octet (20 = 8 + 8 + 4). The block size there is 256 − 240 = 16, so networks start at x.x.0.0, x.x.16.0, x.x.32.0 and so on. `10.4.37.9/20` is in `10.4.32.0`, and its broadcast is `10.4.47.255`. It has 2¹² − 2 = 4094 usable hosts.
