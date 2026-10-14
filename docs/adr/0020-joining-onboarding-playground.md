# ADR 0020: Signing up, getting started, requests to teach or follow a child, and the code playground

Date: 2026-10-14 · Status: accepted

## Context

Until now every account was made by school staff. The school wants learners, especially programmers and people changing career, to be able to join by themselves, find the right courses quickly, and try code without installing anything. Teachers and parents also need a way in that the school still controls.

## Decision

* **Three ways a school can let learners in** (`tenants.registration`, School → Joining): *closed* (staff add people; the default, so nothing changes until a school decides), *code* (anyone with the school's 8-character join code; no 0/O or 1/I; capitals, spaces and dashes ignored; a new code replaces the old one), *open* (anyone; the join page is then listed for search engines). Self sign-up always makes a **learner**; nobody can make themselves a teacher, admin or guardian.
* **The join form** (`/join`) asks for name, email, password (the usual 12-character rule), the code when needed, and two ticks: the school's rules and privacy policy (linked when the school has published them), and "18 or older, or a parent or guardian agrees". A hidden field catches bots; 10 sign-ups per hour per connection per school. Someone who already has a DML account at another school joins with that account's password (throttled like sign-in) and keeps their name; someone the school removed cannot rejoin themselves. Two-step accounts are sent to sign in normally.
* **Email confirmation.** Accounts made by staff count as confirmed (migration sets `email_verified_at = created_at`). Self sign-ups get a single-use, hashed `verify` link when the school sends email; the home and account pages show a reminder with "send the link again" (3 per hour). A `verify` token can never set a password: the reset and invitation pages reject it. Asking to teach needs a confirmed email when email is on.
* **Getting started** is a wizard (`/setup/welcome`, ADR 0010) with a run per person (`Wizard::runKey()`): what you want to learn (up to three goals as large tiles), what you already know (new / basics / already working with it, plus optional GitHub username and portfolio), and a weekly goal (1, 2, 3, 5 or 7 days). Answers live in `learner_profiles` (one row per membership, deleted with it), visible to the learner and, for the developer links, to staff reviewing their work. Every learner can open it later; the home page offers it with "Not now".
* **Suggestions.** The home page orders published courses the learner has not joined by goals (whole-word matches in title, summary and outcomes; the school-subject track) and level fit, and says why on each card ("For you: Learn to code"). Nothing is hidden; courses that match nothing come after.
* **Requests the school decides** (`join_requests`): a member asks to teach (subjects, experience, optional https link); approving makes them an educator through the normal role change, with a notification either way. A parent or guardian, usually without an account, asks to follow a child (name, email, optional phone, child's name, relationship, consent to be contacted). Nothing is linked automatically: staff check who they are, link them with the guardian flow (ADR 0016) and mark the request done. 5 per hour per connection and 3 per day per email address; schools can turn parent requests off (the page then returns 404). Owners and admins are notified; the sidebar shows the waiting count.
* **Code playground** (`/playground`): JavaScript run in the existing sandboxed runner (an opaque-origin iframe with `sandbox="allow-scripts"` only, then a Web Worker), printing `console.log` output, stopped after 4 seconds, 300 lines at most. Nothing reaches the page, the session or the network. Snippets (up to 50 per person, 20,000 characters each) are private to their author; every query is scoped by school and person.
* **Brand.** The TridaxLabs mark (from the supplied logo, transparent PNG at 1× and 2×) sits beside the "Developed and powered by TridaxLabs" credit.

## Consequences

* A school can open sign-up in one click and close it again without affecting existing accounts.
* Teachers and guardians still only gain access through a person at the school.
* The playground runs only JavaScript in the browser; languages that need a server (Python, shell) wait for the isolated execution service.
