# ADR 0033: Full page drafts, platform pages, everyday learning, offline lessons, virus scanning

Date: 2026-10-27 · Status: accepted

## Context

The school asked for its remaining website pages, a page about the developers, virus scanning of uploads, a way for people outside formal schooling to learn here (with a complete course), and a better experience for people who are often offline.

## Decision

### Full drafts of the school's pages

* ADR 0019 kept starter drafts full of [[gaps]] so no invented fact reaches the public site. That stays the default. Next to it, **Start from a full draft** (School → Website → a page) saves a complete draft of Admissions, Contact, Privacy, Terms, Refunds or Safeguarding.
* Facts come from the school's settings and data: name, email, phone, levels, the published courses with their prices, whether online payment is on, what the platform does with personal data (taken from the data dictionary). Where a policy needs a decision (a refund window, how long accounts are kept, who the safeguarding lead is), the draft makes a common, cautious choice, and the editor lists those choices before the button is pressed.
* A full draft is only ever saved as a draft. A person publishes it, as before. A published page is never replaced by a draft.
* `bin/console site:full-drafts <school> [--publish]` does the same for every unpublished page from the server, printing each choice.

### Platform pages

* `/developers`: who built the platform (TridaxLabs), that course and account questions go to the school, how to report a security problem, and what else TridaxLabs builds.
* `/accessibility`: the accessibility statement (WCAG 2.2 AA target, what is tested, what is known not to be right yet, how to report a problem).
* `/.well-known/security.txt` (RFC 9116). The contact is `SECURITY_CONTACT` in `.env` (an email or https address), or tridax.org.
* Both pages are linked from every footer and listed in the sitemap.

### Everyday (informal) learning

* A third course track, **Everyday learning** (`courses.track = 'everyday'`), for practical courses for life and work outside a curriculum: phones, money online, selling, staying safe. Learners can filter by it, the welcome questions offer "Everyday digital skills" as a goal and recommend these courses first, and the public home page shows them under "Learn for everyday life".
* A new institution type, **Community or informal learning** (`tenants.kind = 'community'`), suggests no terms, no levels and the word "group".
* Course packages can say `"access": "open"` (anyone can read without an account) and `"required": false` per lesson.
* A complete course ships with the platform: **Everyday Digital Skills: Your Phone, Your Money, Your Business** (`courses/everyday-digital-skills`): 4 modules, 10 readings, the phishing lab, 4 quizzes with 24 explained questions, 2 optional reflective assignments. Free and open. Assignments and the extra reading are optional, so a learner with no teacher can still finish and earn the certificate.

### Offline

* **Save for offline** on a lesson, and **Save the whole course for offline** on the course page, keep a copy of the lesson pages in the browser's Cache Storage under a name tied to the person (`dml-saved-<hash of school and person>`). The service worker serves them only when there is no network; online, pages always come from the server.
* Signing out removes the saved lessons and anything waiting to be sent. Another person signing in on the same device removes the previous person's copies.
* **Outbox:** "Mark as complete" pressed offline is kept on the device and sent when the connection returns, with a message saying so. Other forms pressed offline say plainly that they need a connection instead of showing a browser error.
* The offline page lists the lessons saved on the device. **Saved and notes** shows them too.
* Videos and recordings are not saved by "Save for offline" (they are large); their existing download links still work.

### Virus scanning

* `deploy/server/setup-clamav.sh` turns scanning on. It reuses a ClamAV daemon already listening on 127.0.0.1:3310, or iRedMail's own ClamAV (one copy of the virus database in memory instead of two), or installs ClamAV when enough memory is free. It backs up each file it changes, reloads PHP-FPM gracefully and never touches Apache, DNS or mail routing.
* `bin/console files:scan-test` checks the scanner with the EICAR test text.

## Consequences

* Full drafts are a starting point, not legal advice; the editor says so for policies, as before.
* Saved lessons hold personal pages (with the person's name and progress) on the device until sign-out. On a shared device, people should sign out; the page says saved lessons are removed when they do.
* An outbox item is sent with the session it was made in. If the person signed out in between, it is removed with the rest; if the session expired, they are asked to sign in and it is sent then.
* Sharing iRedMail's ClamAV means its configuration now also allows larger streams (for video uploads). Mail keeps the size limits set in amavisd.
