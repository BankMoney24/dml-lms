# Interface overhaul: audit and checklist

Started 26 Oct 2026 (update 31). Work goes in batches; each batch adds its results here. ADR 0031 records the decisions for batch 1.

## Stack and tools (as found)

* PHP 8.3 modular monolith, server-rendered views (`resources/views`), MySQL 8 / MariaDB 10.11, no front-end build step.
* One stylesheet (`public/assets/app.css`), one enhancement script (`app.js`), plus `classroom.js` and `studio.js` on their pages. Strict Content Security Policy: no inline scripts or styles.
* Tests: PHPUnit (feature, unit, security), PHPStan level 8, PHP_CodeSniffer (PSR-12). Browser checks: `tests/browser/ui_check.py` (Playwright for Python, development only). Accessibility scan: axe-core 4.10, injected by the audit and browser checks, never shipped.

## Inventory

* 98 GET routes, 138 POST routes, 118 view files, 31 modules.
* Roles: visitor, learner, guardian (parent), educator, admin, owner, platform administrator.
* Areas: public site and course catalogue; joining, sign-in and recovery; learner home, lessons, quizzes, labs, Code studio, certificates, saved notes, achievements, learning paths; live classes, the online classroom and meetings; teaching (courses, lessons, questions, reviews, applications, paths, progress); cohorts; school settings, website, payments, classroom, help desk, people, joining, reports, activity; platform System and Schools.

## Batch 1 audit (crawl results)

The crawl signed in as each role and opened 110 page and role combinations at 360, 390, 430, 768, 820, 1024, 1280 and 1440px (880 page renders). axe-core ran at the narrowest and widest widths, in light mode and again in dark mode for 31 pages.

| Check | Before batch 1 | After batch 1 |
|---|---|---|
| Pages that scroll sideways (any width) | 0 | 0 |
| Script errors | 0 | 0 |
| Internal links that fail (440 checked) | 0 | 0 |
| axe violations, light mode | 6 pages: contrast (parent sign-up note; Code studio line numbers and comments), no `h1` (certificate), scroll areas not reachable by keyboard (3 tables and code samples) | 0 |
| axe violations, dark mode | 3 pages: contrast (dates of ended classes; keyboard hints on the Run button) | 0 |
| Standalone links under 24px (WCAG 2.2, 2.5.8) | Breadcrumbs, course kicker, Code studio language links, panel links, quiz editor answer buttons | Fixed |
| People list | One long table that silently stopped at 1000 members; no role filter | Server-side find, role filter, order and pages of 50 |

Notes on the raw results:

* Pages answering 404 in the crawl are expected: the six website pages are not published yet on the development school, a learner may not open another class's cohort or meeting, and System is for platform administrators.
* The crawl measures the Device / Light / Dark buttons in the closed account menu as 8px wide. That is the closed menu; open, each button is about 100 × 54px (checked by screenshot).

## Batch 1 changes

- [x] Audit crawl and accessibility scan, findings above
- [x] Password fields: Show / Hide everywhere through the shared field helper, Caps Lock warning, masked again on send
- [x] Forms cannot be sent twice; the pressed button shows it is working (reduced-motion aware)
- [x] Warning before leaving a page with unsaved writing
- [x] People: find, role filter, order, pages; phone layout as stacked rows with the Manage panel full width
- [x] Reusable pager (`App\Core\View\Pager`, `partials/pager.php`)
- [x] Sortable report, progress, cohort, payment and school tables
- [x] "Ready for learners?" checklist on School settings, worked out from real settings
- [x] Contrast, heading, keyboard-scroll and target-size fixes from the scan
- [x] Design system written down (`docs/design-system.md`)
- [x] Tests: PHPUnit for paging, filters and the checklist; browser checks for widths, password toggle, double submit, filters, sorting and the unsaved-changes warning

## Still to do (next batches)

Interface:

- [x] List tools on payments (find, course, status counts, pages, matching export) and activity (kind, person, days) (update 36)
- [x] List tools on the help desk and applications (update 37)
- [x] Unsaved-changes warning on the setup wizard steps (update 37)
- [ ] Screen-reader passes: NVDA with Firefox, VoiceOver on iPhone, TalkBack on Android, for sign-in, a lesson, a quiz, the classroom and a payment
- [ ] Real-device checks on a low-end Android phone on 3G for the lesson, quiz and classroom pages
- [x] Empty states reviewed for a brand-new school (update 36)
- [ ] Terminology setting ("student" or "learner", "class" or "cohort")

Pending features (from the invoice's pending list):

- [x] Everyday (informal) learning track, community school type, and a complete free course (update 33)
- [x] Developers page, accessibility statement and security.txt (update 33)
- [x] Blog, programmes page (formal and informal learning), FAQ and careers pages, footer columns (update 34, ADR 0034)
- [x] Network builder (Packet Tracer style) with build and repair labs; firewall, DNS, pendulum and osmosis labs; Packet Tracer files; Networking Foundations course (update 35, ADR 0035)

- [x] Refunds and discount codes (update 32, ADR 0032)
- [x] Instalments: pay a course in 2–4 monthly parts (update 37, ADR 0037)
- [x] Per-course completion rules (update 32)
- [x] Learner report sheets for parents and staff, printable (update 32)
- [x] Public API v1 (for PrimeERP and others) with per-school keys (update 36, ADR 0036)
- [x] Offline lessons: save a lesson or whole course, outbox for finished lessons (update 33)
- [x] Threat model (`docs/threat-model.md`) and data dictionary (`docs/data-dictionary.md`) (update 32)
- [ ] SMS / WhatsApp: needs a provider account and a consent review first
- [ ] Recording and YouTube streaming: needs a separate Jibri server (more memory than the current server can spare)
- [ ] Real-machine labs: needs isolated lab servers
- [ ] Network builder next steps: VLANs, DHCP and dynamic routing (OSPF) in the simulator

Server and account tasks (done by the school on the server; instructions in each release note):

- [ ] Rehearse a class and a meeting with two real devices
- [ ] Rotate the backup key, store it off the server, set `REMOTE`
- [ ] Email provider, SPF and DKIM; Google Search Console
- [ ] Paystack live key
- [ ] Publish the website pages: full drafts can now be saved in one step (School → Website, or `site:full-drafts`), then read and publish
- [ ] Read and publish the starter blog posts (School → Website → Blog, or `blog:starter-posts`)
- [ ] Staging copy

## How to repeat the checks

```
vendor/bin/phpunit                                              # MariaDB or MySQL, as configured
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpcs -q
python3 tests/browser/ui_check.py --base http://dml.localhost:8080 --axe path/to/axe.min.js
```
