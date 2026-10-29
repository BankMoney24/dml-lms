# ADR 0039: About us from the school's own facts; putting every page on the website in two steps

Date: 2026-10-29 · Status: accepted

## Context

The school's About us, Admissions, Contact and policy pages exist only as drafts until an administrator publishes them (ADR 0019). Only published pages answer and appear in the menu and footer. On a live school that had not done this yet, the site looked as if those pages were missing. Full drafts (ADR 0033, 0034) covered every page except About us, which still started with gaps in [[double brackets]] and so could never be published without writing. Publishing also meant opening nine pages one by one.

## Decision

* **About us gets a full draft**, built only from facts the school entered or published:
  * Its name and tagline, its kind (school, polytechnic, training centre, and so on) and its levels.
  * Its published courses, grouped by track, with level, length and summary.
  * What the platform really does here. Labs are mentioned only if a published course has one, certificates only if they are on, and parents only if parent requests are on.
  * How to reach it.
* The draft says nothing about when the school was founded, where it is, its staff, partners, results or accreditation, and its listed choices say so. The administrator adds those if they want them.
* **School → Website → Put your pages on the website** (`/school/website/ready`), linked from the Website tab whenever a page is missing and from the launch checklist:
  1. **Write the missing pages:** a full draft for every unpublished page that is not started or still has gaps. A finished draft the school wrote itself is never overwritten.
  2. **Read them, then publish:**
     * Every unpublished page is listed with what still stops it (for example, Contact needs an email or phone in Settings) and what its draft decides.
     * Each page has a "Read it" preview and an Edit link.
     * Ready pages are ticked; pages that are not ready cannot be ticked.
     * One button publishes the ticked pages. Each page is checked again on the server, and each publication is audited.
  3. **News on your blog:** shown while no post is published, pointing to the blog's starter posts.
* Nothing is published without a person pressing Publish. `bin/console site:full-drafts <school> [--publish]` covers About us too, for administrators working on the server.

## Consequences

* A new school can have every page live within minutes, and each one still reads only what the school told the platform.
* The About us draft goes stale as courses change. Like any page, it is edited or rewritten on its page; drafts are never refreshed by themselves.
