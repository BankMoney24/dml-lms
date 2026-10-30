# ADR 0041: OSPF in the network builder, the school's word for learners, and screen fixes

Date: 2026-10-29 · Status: accepted

## Context

After the interface overhaul (ADR 0040) the school asked us to keep fixing what was left and to add features. This update covers three things:

* **Screens.** An audit of the screens ADR 0040 only touched lightly (the teacher course editor, the lesson page on an iPad, school settings, payments, labs, reports and people) at 390, 820 and 1440px found these problems:
  * On phones, lists that become stacked cards (API keys, discount codes, payments) kept their table cell padding, so long values such as key prefixes ran into the next column.
  * In report figures, an odd number of figures left a grey hole in the grid.
  * Row headings in tables looked like column headings: small grey text on a grey background.
  * On iPads, the whole course outline came before the Next lesson button.
* **Networking.** After static routes (ADR 0035) and VLANs (ADR 0038), the next topic in a networking course is a routing protocol. Two more gaps:
  * Static routes do not survive a failed link, and there was nothing in the builder to show that.
  * Neither copy of the simulator accepted a default route (`0.0.0.0 0.0.0.0`).
* **Terminology (TEN-004).** Many schools say "student", primary schools say "pupil" and training centres say "trainee", but every page said "learner".

## Decision

### OSPF in the network builder

* **Configuration.** A router can run OSPF with up to eight network statements (network, wildcard, area), a list of passive interfaces, and "default-information originate". The compact JSON gains `"os": {"n": [[network, wildcard, area]…], "pi": [port…], "di": 1}`.
* **The model.** It is the same in `Simulator` and in `network-builder.js`, and it is written out in Simulator's header. The model is:
  * one link-state database for the whole network;
  * an interface runs OSPF when it is working and a network statement matches it as (address AND NOT wildcard) = network;
  * two routers become neighbours across a segment when both interfaces run OSPF, neither is passive, and they share the network, mask and area;
  * every OSPF interface's network is advertised, passive ones included;
  * shortest paths are counted in hops (every link costs 1), and ties go to the first path found, in a fixed order;
  * at the same prefix length, a connected route beats a static route, and a static route beats an OSPF route.
* **What is left out.** Areas only decide adjacency; there are no area border rules, no DR election, and no timers. The lesson says that multi-area OSPF belongs to a later course.
* **Default routes.** A route mask of `0.0.0.0` now means a default route, in both copies. A router with a default route and "originate" shares it as `O*E2`.
* **Interface.** Routers get an OSPF tab, which shows:
  * the network statements, with add and remove;
  * passive interfaces as tick boxes;
  * a box for sharing the default route;
  * what the router sees now: its neighbours and the routes it learned.
* **Command line.** The CLI gains:
  * `router ospf`, `network … area`, `passive-interface` and `default-information originate`, each with `no`;
  * `show ip ospf neighbor`, `show ip ospf interface brief` and `show ip protocols`;
  * O routes in `show ip route`, and the OSPF block in the running config.
  * Typing a mask where the wildcard goes is caught and explained.
* **Port labels.** A port name on a cable leaving a device downwards now slides along the cable until it clears the device's name. Device names get a halo, so they stay readable where cables cross them.
* **The lab.** A new lab, "Dynamic routing with OSPF" (`network-ospf`), has three sites with routers in a triangle, all addressed, and no routes. It is marked out of 100:

  | Check | Marks |
  |---|---|
  | OSPF runs on every interface, in area 0 | 25 |
  | Neighbours on all three links | 20 |
  | The LAN ports are passive | 10 |
  | Every site reaches every other, with no static routes | 25 |
  | Still every site, with each router-to-router link cut in turn on the server | 20 |

* **The course.** The Networking Foundations package gains module 8, "Dynamic routing with OSPF": a lesson on neighbours, wildcards, passive interfaces, checking your work and sharing a default route, then the lab and a four-question check.

### The school's word for learners

* `tenants.learner_term` holds learner (the default), student, pupil or trainee. The school chooses it in setup on the "Your academic year" step, next to the word for a group, and it is shown in School → Settings.
* `WordSwap` changes finished pages, so no template has to change. It swaps "learner" for the school's word, keeping case and number ("Learners" → "Students"). It works on two things:
  * text between tags;
  * the attributes people read: placeholder, title, aria-label, alt, data-label, the value of a submit button, and a page description.
* It leaves these alone:
  * code: `script`, `style`, `textarea`, `pre` and `code`;
  * what people wrote: anything marked `data-own-words` or with the class `prose`. That covers lesson text, posts and site pages, multi-paragraph messages (the `paragraphs()` helper marks them), and answers on the setup review;
  * every other attribute, including links and form values;
  * addresses and file names, such as `/learners`, `learners.csv` and `?learner=`.
* Emails keep the platform's words. None of their templates says "learner", and an email's text has no markup to show what people wrote.

### Smaller fixes and features

* **Stacked cards on phones.** They have no cell padding, use a two-column grid that adapts to the space, wrap long values and use bold labels.
* **Report figures** have no gaps. **Table row headings** read as the first cell of the row.
* **Lesson page.** Next and Previous come straight after the lesson, before questions and the outline, at every width.
* **"I have paid the school."** The amount field fills in from what the payment is for: the whole course, part 1, or the next part of a plan. It changes when that choice changes, until the learner types an amount of their own.

## Consequences

* Checks:
  * Tests pass on MariaDB and MySQL 8, including new ones for OSPF and default routes, the OSPF lab, the word swap and the setup step.
  * A comparison of the two simulators on 800 randomly altered OSPF networks (48,000 checks of neighbours, learned routes and pings) found no differences.
  * The VLAN comparison (25,200 checks) still finds none.
* The word swap runs on every full page for schools that choose a word other than "learner". It is a single pass over the page: 1 to 3 ms for a typical page. People's names are not marked, so someone whose surname is "Learner" would be shown with the school's word.
* The OSPF model is simpler than real OSPF, on purpose. The lab marks only what the model covers: statements, wildcards, passive interfaces, neighbours, learned routes and failover.
