A full disk is one of the most common causes of a broken server: databases stop writing, logins fail, websites show errors. You need to know how to see what storage you have, what is filling it, and how to add more.

## What storage is there?

```bash
lsblk                 # disks and partitions as a tree
df -hT                # mounted filesystems: type, size, used, free
```

```text
NAME   MAJ:MIN RM  SIZE RO TYPE MOUNTPOINTS
vda    252:0    0   40G  0 disk
├─vda1 252:1    0    1G  0 part /boot
└─vda2 252:2    0   39G  0 part /
vdb    252:16   0  100G  0 disk
```

Here `vdb` is a second disk that is not yet used.

## What is filling the disk?

```bash
sudo du -xh --max-depth=1 / | sort -h          # biggest top-level folders on this filesystem
sudo du -sh /var/log/* | sort -h | tail
sudo find / -xdev -type f -size +200M -exec ls -lh {} \; 2>/dev/null
sudo journalctl --disk-usage
```

The usual suspects: old logs, forgotten backups, the package cache, uploaded files, and database binary logs.

```bash
sudo journalctl --vacuum-size=200M    # shrink the journal to 200 MB
sudo dnf clean all                    # AlmaLinux package cache
sudo apt clean                        # Ubuntu package cache
```

> **Note:** A disk can also be "full" of **inodes** (the entries that track files) while still showing free space, usually because of millions of tiny files such as old sessions or cache entries. Check with `df -i`.

> **Warning:** Deleting a log file that a program still has open does not free the space until the program closes it. Empty it instead with `sudo truncate -s 0 /var/log/big.log`, or restart the service. `sudo lsof +L1` lists deleted files that are still open.

## Adding a disk

After attaching a new disk (here `vdb`) in your provider's panel or VM settings:

```bash
sudo parted /dev/vdb --script mklabel gpt mkpart data xfs 0% 100%
sudo mkfs.xfs /dev/vdb1                 # ext4 is also fine: sudo mkfs.ext4 /dev/vdb1
sudo mkdir -p /srv/data
sudo blkid /dev/vdb1                     # note the UUID
```

> **Warning:** `mkfs` erases the partition you give it. Check the device name with `lsblk` twice. Formatting the wrong disk destroys your system.

Mount it at boot by adding a line to `/etc/fstab`. Use the UUID, because device names like `vdb` can change between boots:

```text
UUID=5d2c1a70-8e4b-4f3e-9b6f-2a1c0d9e7f11  /srv/data  xfs  defaults,nofail  0 0
```

`nofail` lets the server still boot if this disk is missing. Then test the file **before** rebooting:

```bash
sudo systemctl daemon-reload
sudo mount -a          # mounts everything in fstab; errors here would also break the next boot
df -hT /srv/data
```

On AlmaLinux with SELinux, if a service will store data there, label it the same way as the original location (see module 7), for example `sudo semanage fcontext -a -t httpd_sys_content_t "/srv/data/www(/.*)?"` then `sudo restorecon -Rv /srv/data/www`.

## Try it

1. Find the three biggest folders under `/var`.
2. Check both space and inodes on `/`.
3. If you have a VM, add a small second disk, format it, and mount it permanently at `/srv/data`. Reboot and confirm it is still mounted.
