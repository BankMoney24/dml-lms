# ADR 0027: Code studio, networking and Python labs, and sample data for testing

Date: 2026-10-20 · Status: accepted

## Context

The school asked for programming IDEs, networking traces, and enough data in the system to test it properly. The code playground (ADR 0020) only ran JavaScript in a plain text box. The labs had subnetting but nothing about how packets actually travel. Testing the platform meant clicking through an almost empty school.

## Decision

### Code studio (`/playground`)

* **Three languages**, each sealed off in an opaque-origin iframe. No learner code runs on this page or on the server.
  * **JavaScript**: runs in the existing runner's Web Worker.
  * **Python 3**: [Skulpt](https://skulpt.org) (MIT licence, about 1 MB, shipped in `public/assets/vendor/skulpt` with its licence). The page fetches it once as text and hands it to the runner. It runs in a Web Worker with a time limit. `input()` reads from an Input box; JavaScript's `prompt()` does the same.
  * **Web page**: one HTML file with its own `<style>` and `<script>`, written into `/labs/web-runner`. That page is framed with `sandbox="allow-scripts"`. Its policy allows the page's own inline code and `data:` images, and sets `connect-src`, `form-action` and `frame-src` to `'none'`. `console.log` and errors from the page are shown under the preview.
* **The editor**:
  * Syntax highlighting is a coloured copy under a transparent textarea, so typing, selection, undo and screen readers stay native.
  * Line numbers and a line/column indicator.
  * Keys:
    * Tab / Shift-Tab indent or outdent the selected lines (Escape then Tab leaves the editor).
    * Enter keeps the indentation, one level deeper after `{` or a Python `:`.
    * Brackets and quotes close themselves.
    * Ctrl+/ comments lines in or out; Ctrl+S saves; Ctrl+Enter runs.
* **Examples** for each language. Snippets remember their language (`code_snippets.language`).
* **Coding challenges** (`/playground/challenges`): 12 short functions (strings, numbers, decisions, loops, lists, subnet hosts), each in JavaScript or Python.
  * The tests run in the same sealed runner and are compared on the page; expected values are shown as each language writes them.
  * Passing all tests offers *Save as solved* (`code_challenge_solves`). This is the learner's own practice record, labelled as such, not a marked result.

### New labs (server-marked, from a per-session seed, ADR 0011)

* **`packet-capture`**: a Wireshark-style capture of one web visit (ARP, DNS with an unrelated lookup mixed in, the TCP handshake, HTTP, the close).
  * Packets are built byte by byte, so the hex view is a real Ethernet/IPv4/TCP/UDP/ARP layout.
  * The viewer has a display filter (`dns`, `tcp`, `ip.addr==…`, `tcp.port==…`), decoded layers and a hex dump.
  * Questions: the gateway's MAC address, the DNS server, the resolved address, the client port, the packet that completes the handshake, and the HTTP status.
* **`network-trace`**: PC1 cannot reach a server across four routers.
  * A command prompt simulates `ipconfig`, `ping`, `tracert`, `arp -a`, `nslookup`, `show ip route` and `show interfaces`. Routing uses longest-prefix match and TTL, and a reply arrives only if the route back works.
  * One fault per session: a wrong default gateway, a missing route, a routing loop, or a missing return route.
  * The learner names the faulty device, the fault, the correct next hop and the server's network. The answer key stays on the server.
* **`py-leap`**: write `is_leap(year)` in Python. As with the JavaScript lab, it runs in the learner's browser on fresh years chosen per session, and the outputs are marked on the server.
* Two course packages use them: **Network Troubleshooting: Packets, Routes and Traces** and **Python: First Programs**.

### Sample data (`bin/console sample:seed`)

* Fills a school with people and about 90 days of activity: staff, learners, guardians, cohorts with teachers, assigned courses and announcements, learning paths and followers.
  * The activity: enrolments, lesson progress, quiz attempts, lab sessions, assignments with reviews and resubmissions, and certificates.
  * Payments: online (test mode), recorded by hand, and payment notes that are confirmed, rejected or still pending.
  * Also: applications (offered, declined, waiting), questions and answers, notes, snippets, challenge solves, help-desk requests, join requests, notifications and achievements.
* It drives the **real services** with a clock that moves through time, so every rule (payments before access, waiting lists, completion, certificates, notifications, audit) is exercised and every date looks real. It all runs in **one transaction**: if anything fails, nothing is left behind.
* **Safety**:
  * Every account uses `@sample.test`, a reserved domain that never receives email. All accounts share one generated password, printed once.
  * In production it only fills a school it created itself (`--create="…" --host=…`, marked in the audit log), never an existing school.
  * A school can be filled only once.
  * The sample school is kept out of search engines unless `--public` is given.

### Small fixes found by crawling the sample school

* Teachers following a lesson link (from a notification or a report) go to the teacher preview instead of a 403.
* "Your answer helped" sends teachers to their questions inbox.
* Guardians open a child's certificate through the public check page.

## Consequences

* Learners can practise three languages and real networking diagnosis without installing anything, and teachers get marked Python and networking labs.
* Real-machine labs (a real Linux shell, emulated network devices) still need an isolated execution service. The simulated network covers diagnosis, not configuration.
* Skulpt implements most of Python 3 but not all of it (for example, some standard library modules). The studio says what it ran or the error it hit.
* Sample schools give realistic screens for testing, demos and training. A separate sample school (its own address) keeps the real school's figures clean.
