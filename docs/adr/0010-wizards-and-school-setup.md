# ADR 0010: Wizard engine, school setup and school administration

Date: 2026-10-13 · Status: accepted

## Context

PRD §6.3 asks for one reusable wizard engine rather than a hand-built wizard per feature, with progress, Back / Continue / Save and exit, server-side progress that survives sign-out and lost connections, safe skipping of optional steps, and a review step before anything is applied or invitations go out in bulk. §6.4 describes the institution onboarding wizard, and acceptance criterion 1 says a platform administrator creates a tenant and an institution admin completes setup without a developer.

## Decision

* **Engine** (`app/Modules/Wizards`). A wizard definition lists `WizardStep`s and says who may run it, what blocks finishing, and what finishing does. A step reads its form, validates it into saved data, gives starting values (usually what the institution already has, so nothing is asked twice) and lines for the review. `WizardController` does everything else, the same for every wizard, at `/setup/{wizard}/{step}`.
* **Progress lives in `wizard_runs`**, one open run per wizard per institution, saved after every step and shared by everyone allowed to run it. A step opens only when the steps before it are answered or (if optional) skipped. Nothing is applied until "finish" on the review step; the run is then deleted, and the outcome lives in the real tables. "Discard" deletes the run and lets the wizard remove anything it stored on the way.
* **Settings are edited with the same wizard.** Once a school is set up, a new run starts with every step answered from current settings, so any step opens directly from the School page and the review shows the whole picture before "Save changes".
* **School setup** has 7 steps: welcome, type of institution, academic year (optional), name and look, learning features, invite your team (optional), check and launch. Billing and policy (PRD step 7) arrive with payments.
* **Themes are a fixed set of CSS classes**, each checked for WCAG AA in light and dark mode. A free colour picker could produce unreadable buttons, and the CSP forbids inline styles anyway.
* **Logos**: PNG, JPEG or WebP only (never SVG, which can carry script), at most 512 KB, checked by content and scanned like other uploads, stored with the uploads so backups include them, and served publicly at `/logo?v=<hash>` with a week's caching. A logo uploaded in the wizard is "pending" until finish.
* **Invitations from the wizard** use the same `MemberService` and email links as the People page, after every line has been validated, and only on finish. Without email, one-time passwords are shown once on the next page.
* **Audit**: `wizard.finished` records which steps were answered or skipped, never the values typed.
* **Schools for platform administrators** (`/system/schools`): create a school with its address and first owner, add addresses, suspend and reopen. A platform administrator cannot suspend the school whose address they are signed in through.

## Consequences

* New setup journeys (a child's first run, lab provider setup) only need step classes and views.
* The academic structure is stored but not yet used by courses or reports; Phase 2 builds on it.
* Adding an address in the app does not configure DNS, the web server or HTTPS; the deployment guide lists those steps.
