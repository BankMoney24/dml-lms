# ADR 0031: Interface overhaul, batch 1 (audit, forms, long lists, launch checklist)

Date: 2026-10-26 · Status: accepted

## Context

The school asked for a full pass over the interface: every page checked on phones, tablets and desktops, accessibility to WCAG 2.2 AA, forms that cannot be sent twice, clear password fields, tables people can sort and page through, and no unfinished screens. The platform already had one design system (ADR 0003 and later), so the work is an audit followed by targeted fixes, not a new look. URLs, data and business rules stay as they are.

## Decision

1. **Audit first, in the repository.** A crawl signs in as a visitor, a learner, a teacher and an administrator, opens 74 pages at eight widths (360 to 1440px), and records sideways scrolling, script errors, links that fail, small touch targets and axe-core results. Results and what was done about each finding are kept in `docs/overhaul-checklist.md`. The checks that guard the fixes are in `tests/browser/ui_check.py` (Playwright for Python, a development tool, not a dependency of the application).
2. **Password fields** get Show / Hide and a Caps Lock warning from the shared field helper, so sign-in, joining, reset, account and two-step pages all behave the same. The button's name is "Show password" / "Hide password". The box is masked again when the form is sent.
3. **Sending a form** marks its buttons busy (`aria-disabled`, a ring that does not turn when reduced motion is on) and ignores further submits until the next page arrives. Forms that send themselves (they cancel the submit event) and GET forms are left alone. If no page arrives (a download), the buttons come back after 15 seconds.
4. **Unsaved writing**: a form with a text area, or marked `data-unsaved`, warns before the page is left with edits that were not sent.
5. **Long lists**: People is filtered, searched, ordered and paged on the server (50 a page, `?role=`, `?find=`, `?sort=`, `?page=`), replacing a list that silently stopped at 1000 members. The page count on School settings no longer depends on that list. `App\Core\View\Pager` and `partials/pager.php` are reusable. Report, progress, cohort, payment and school tables can be sorted by their headings in the browser (`data-sortable`, `data-sort` for dates and scores).
6. **"Ready for learners?"** on School settings lists what a school still needs before it opens: a published course, teachers, live payments, the core website pages (and the refund policy once fees are taken online), real email delivery, the online classroom and two-step sign-in for every administrator. Each item is worked out from real settings; nothing is ticked by hand.
7. **Accessibility fixes from the scan**: contrast (parent sign-up note, Code studio line numbers and comments, ended-class dates and keyboard hints in dark mode), a page heading on certificates, keyboard access to areas that scroll sideways, and 24px targets for breadcrumbs and similar standalone links.

## Consequences

* Sorting tables happens only in the browser and only within the page shown; it does not change CSV exports.
* The busy state relies on script. Without it, forms still work, but a double tap could send twice (the server already refuses most duplicates, for example adding the same person twice).
* The automated scan finds roughly a third of accessibility problems. Screen-reader passes (NVDA, VoiceOver, TalkBack) are still to do and are listed in the checklist.
