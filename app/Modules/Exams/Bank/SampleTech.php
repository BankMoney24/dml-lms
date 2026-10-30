<?php

declare(strict_types=1);

namespace App\Modules\Exams\Bank;

/**
 * Sample banks for the school's technology courses (ADR 0042, update 43):
 * networking, cybersecurity, Python and Linux. They follow the platform's own
 * courses and labs (the OSPF answer matches the network builder's model) and
 * were written for this platform.
 *
 * Each question is [prompt, options, correct index, explanation, topic, difficulty].
 */
final class SampleTech
{
    /**
     * @return list<array{name: string, passages: array<string, array{0: string, 1: string}>,
     *     questions: list<array{0: string, 1: list<string>, 2: int, 3: string, 4: string, 5: int}>}>
     */
    public static function banks(): array
    {
        return [
            [
                'name' => 'Networking Fundamentals (sample)',
                'passages' => [],
                'questions' => [
                    ['How many bits are in an IPv4 address?', ['16', '32', '64', '128'], 1, 'An IPv4 address is 32 bits, written as four 8-bit numbers.', 'IP addressing', 1],
                    ['Which device forwards packets between different networks?', ['hub', 'switch', 'router', 'repeater'], 2,
                        'Routers join networks; switches join devices within one network.', 'Devices', 1],
                    ['Which subnet mask matches a /24 prefix?', ['255.0.0.0', '255.255.0.0', '255.255.255.0', '255.255.255.255'], 2, '24 one-bits: 255.255.255.0.', 'Subnetting', 1],
                    ['How many usable host addresses does a /26 subnet have?', ['30', '62', '64', '126'], 1, '2⁶ = 64 addresses, less the network and broadcast addresses: 62.', 'Subnetting', 2],
                    ['Which protocol gives devices an IP address automatically?', ['DNS', 'DHCP', 'FTP', 'ARP'], 1,
                        'DHCP leases addresses, masks, gateways and DNS servers to clients.', 'Network services', 1],
                    ['DNS is used to', ['encrypt web traffic', 'turn names such as example.org into IP addresses', 'give out MAC addresses', 'route between VLANs'], 1,
                        'DNS resolves names to addresses.', 'Network services', 1],
                    ['Which of these is a private IPv4 address?', ['8.8.8.8', '192.168.1.10', '172.32.0.1', '100.1.1.1'], 1,
                        'The private ranges are 10.0.0.0/8, 172.16.0.0/12 (172.16–172.31) and 192.168.0.0/16. 172.32.0.1 is outside them.', 'IP addressing', 2],
                    ['At which OSI layer does a switch normally work?', ['Layer 1, physical', 'Layer 2, data link', 'Layer 3, network', 'Layer 7, application'], 1,
                        'A switch forwards frames by MAC address, at the data link layer.', 'The OSI model', 1],
                    ['Which command checks whether another host replies?', ['ping', 'hostname', 'mkdir', 'exit'], 0, 'ping sends ICMP echo requests and reports the replies.', 'Troubleshooting', 1],
                    ['What does a VLAN do?', ['joins two routers with one cable', 'splits one switch into separate broadcast domains', 'encrypts Wi-Fi', 'gives every device a public address'], 1,
                        'Each VLAN is its own broadcast domain; traffic between VLANs needs a router.', 'Switching', 2],
                    ['In OSPF, a passive interface', ['advertises its network but forms no neighbours', 'is shut down', 'forms neighbours but advertises nothing', 'carries only default routes'], 0,
                        'Making a LAN port passive stops hellos there while its network is still advertised.', 'Routing', 2],
                ],
            ],
            [
                'name' => 'Cybersecurity Basics (sample)',
                'passages' => [],
                'questions' => [
                    ['Phishing is', ['a way of cooling servers', 'tricking people into giving away information, usually with fake messages', 'a kind of firewall', 'a backup method'], 1,
                        'Phishing messages pretend to come from someone you trust.', 'Social engineering', 1],
                    ['Which of these is the strongest password?', ['password123', 'Tunde2008', 'correct-horse-mango-river-42', '12345678'], 2,
                        'Length matters most: a passphrase of several random words is hard to guess and easy to remember.', 'Passwords', 1],
                    ['Two-step sign-in protects an account by', ['making the password shorter', 'asking for a second proof, such as a code from an app', 'hiding the username',
                        'turning off the screen'], 1,
                        'Even with your password, an attacker also needs the second factor.', 'Accounts', 1],
                    ['A firewall', ['speeds up the internet', 'controls which network traffic is allowed in or out', 'stores backups', 'removes viruses from files'], 1,
                        'Firewalls allow or block traffic according to rules.', 'Network security', 1],
                    ['Ransomware', ['encrypts your files and demands payment', 'improves battery life', 'blocks spam email', 'updates your software'], 0,
                        'Backups kept offline are the best protection against ransomware.', 'Malware', 1],
                    ['A web address that starts with https:// means that', ['the site is certainly honest', 'the connection to the site is encrypted',
                        'the site belongs to the government', 'the site has no adverts'], 1,
                        'HTTPS protects data in transit. A fake site can use HTTPS too, so still check the address.', 'Web safety', 2],
                    ['Before clicking a link in an unexpected message, you should', ['click quickly before it expires', 'check the sender and where the link really goes',
                        'forward it to all your contacts', 'reply with your password'], 1,
                        'Urgency is a common trick. Check first.', 'Social engineering', 1],
                    ['Why should you install software updates promptly?', ['They often fix security weaknesses', 'They delete your files', 'They make passwords unnecessary',
                        'They switch off the firewall'], 0,
                        'Attackers use known weaknesses that updates fix.', 'Updates', 1],
                    ['The 3-2-1 backup rule means', ['3 copies, on 2 kinds of storage, 1 kept off-site', '3 passwords, 2 emails, 1 phone', 'back up 3 times a day', '3 files in each folder'], 0,
                        'Several copies in different places survive theft, fire and ransomware.', 'Backups', 2],
                    ['Which of these is personal data that should be protected?', ["the school's public address", 'your date of birth and phone number', 'the weather forecast',
                        'a published textbook'], 1,
                        'Personal data identifies you and can be misused.', 'Privacy', 1],
                ],
            ],
            [
                'name' => 'Python Programming (sample)',
                'passages' => [],
                'questions' => [
                    ['What does `print(3 + 4 * 2)` display?', ['10', '11', '14', '24'], 1, 'Multiplication comes first: 4 × 2 = 8, then 3 + 8 = 11.', 'Expressions', 1],
                    ['What type is the value `"42"`, in quotes?', ['int', 'str', 'float', 'bool'], 1, 'Anything in quotes is a string.', 'Types', 1],
                    ['What is `len([5, 8, 2])`?', ['2', '3', '8', '15'], 1, 'len counts the items: three.', 'Lists', 1],
                    ['What does `7 // 2` give?', ['1', '3', '3.5', '4'], 1, '// is floor division: it drops the fraction.', 'Operators', 1],
                    ['What does `7 % 2` give?', ['0', '1', '3', '3.5'], 1, '% gives the remainder: 7 = 3 × 2 + 1.', 'Operators', 1],
                    ['Which keyword starts a function definition?', ['func', 'def', 'function', 'fn'], 1, 'def name(parameters): starts a function.', 'Functions', 1],
                    ['What does `list(range(3))` give?', ['[1, 2, 3]', '[0, 1, 2]', '[0, 1, 2, 3]', '[3]'], 1, 'range(3) starts at 0 and stops before 3.', 'Loops', 1],
                    ['`names = ["Ada", "Tunde", "Zara"]`. What is `names[-1]`?', ['"Ada"', '"Tunde"', '"Zara"', 'an error'], 2,
                        'Negative indexes count from the end: -1 is the last item.', 'Lists', 2],
                    ['Which line checks whether x equals 10?', ['if x = 10:', 'if x == 10:', 'if x === 10:', 'if x equals 10:'], 1, '= assigns a value; == compares.', 'Conditions', 1],
                    ['What is `"ab" * 3`?', ['"ababab"', '"ab3"', 'an error', '"aaabbb"'], 0, 'Multiplying a string repeats it.', 'Strings', 2],
                ],
            ],
            [
                'name' => 'Linux Basics (sample)',
                'passages' => [],
                'questions' => [
                    ['Which command shows the folder you are in?', ['ls', 'pwd', 'cd', 'whoami'], 1, 'pwd prints the working directory.', 'Files and folders', 1],
                    ['Which command lists the files in a folder?', ['ls', 'cat', 'rm', 'mv'], 0, 'ls lists; cat shows a file; rm removes; mv moves or renames.', 'Files and folders', 1],
                    ['Which command removes a file called notes.txt?', ['rm notes.txt', 'del notes.txt', 'cut notes.txt', 'erase notes.txt'], 0,
                        'rm removes files. There is no recycle bin, so take care.', 'Files and folders', 1],
                    ['On AlmaLinux, which tool installs packages?', ['apt', 'dnf', 'brew', 'choco'], 1, 'AlmaLinux uses dnf; Ubuntu uses apt.', 'Packages', 1],
                    ['What does sudo do?', ['shuts the system down', "runs a command with another user's privileges, usually root's", 'deletes the home folder', 'shows disk usage'], 1,
                        'sudo runs one command as root after checking you are allowed to.', 'Users and permissions', 1],
                    ['A file with permissions 644 gives its owner', ['read and write, and everyone else read only', 'read, write and execute for everyone', 'read only for everyone', 'no access'], 0,
                        '6 = read + write for the owner; 4 = read for the group and others.', 'Users and permissions', 2],
                    ['Which command shows new lines of a log file as they are written?', ['head file', 'tail -f file', 'touch file', 'wc -l file'], 1, 'tail -f follows the end of a file.', 'Logs', 2],
                    ['Which command restarts a service called httpd on a systemd system?', ['service restart httpd now', 'systemctl restart httpd', 'restart httpd --systemd', 'httpd reboot'], 1,
                        'systemctl starts, stops, restarts and checks services.', 'Services', 1],
                    ['In a path, what does ~ stand for?', ['the root folder', "the current user's home folder", 'the previous folder', 'a hidden file'], 1,
                        '~ is your home folder, for example /home/ada.', 'Files and folders', 1],
                    ['Which command searches for text inside files?', ['find', 'grep', 'which', 'pwd'], 1,
                        'grep searches inside files; find looks for files by name and other details.', 'Text tools', 1],
                ],
            ],
        ];
    }
}
