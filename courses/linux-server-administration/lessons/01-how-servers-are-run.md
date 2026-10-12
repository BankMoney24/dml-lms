Most websites, school systems and mobile app back ends you use every day run on Linux servers. In this course you will learn to look after one properly: get in securely, install and run software, serve a website over HTTPS, back it up, and fix it when something breaks.

## What a server is

A server is an ordinary computer whose job is to answer requests from other computers. It usually has no screen or keyboard attached. You manage it remotely, over the network, by typing commands.

Servers come in a few forms:

- **A virtual private server (VPS)** rented from a provider. You get a slice of a bigger machine with its own operating system. This is the cheapest way to get a server on the internet.
- **A dedicated server**: a whole physical machine you rent or own. Often several websites share it.
- **A virtual machine on your own laptop**, using VirtualBox, VMware or Hyper-V. Free, private, and perfect for practice.

## Linux distributions

Linux comes in *distributions* ("distros"): the same kernel packaged with different tools and defaults. On servers you will mostly meet two families.

| Family | Examples | Package tool | Firewall tool you will usually see |
|---|---|---|---|
| Red Hat family | AlmaLinux, Rocky Linux, RHEL, CentOS Stream | `dnf` | `firewalld` |
| Debian family | Ubuntu Server, Debian | `apt` | `ufw` |

The ideas are identical; a few command names and file locations differ. This course shows both wherever they differ, so you can work on whichever server you are given.

> **Tip:** When you sit at an unfamiliar server, the first thing to find out is which family it belongs to: `cat /etc/os-release`.

## Why the command line?

Servers are managed by typing because it is precise, fast over a slow connection, and repeatable. A command you typed once can be written down, shared with a colleague, or put in a script that runs at 3 a.m. A sequence of mouse clicks cannot.

## Set up your practice server

You need a Linux server you are allowed to break. Choose one:

1. **A virtual machine.** Install VirtualBox, download the AlmaLinux 9 or Ubuntu Server 24.04 ISO, and create a VM with 2 GB RAM and 20 GB disk. Choose "minimal" or "server" install, and create a user for yourself with administrator rights.
2. **A small VPS** from any provider. Pick AlmaLinux 9 or Ubuntu 24.04. The cheapest plan is enough.
3. **A server provided by your school**, if your teacher gives you one.

> **Warning:** Never practise on a server that runs real services for other people. Several exercises change security settings; a mistake could lock everyone out.

If you use a virtual machine, take a **snapshot** before each module. If something goes badly wrong you can return to the snapshot in seconds. That habit (make a restore point before a risky change) is exactly what professionals do on real servers too.

## How this course works

Each module has readings with commands to try, a quiz, and in some modules an assignment that a teacher reviews. Type the commands yourself rather than copying them: your fingers learn as much as your eyes. At the end, a capstone project and a final exam earn you the certificate.
