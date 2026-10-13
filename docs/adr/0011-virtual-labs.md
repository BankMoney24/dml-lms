# ADR 0011: Virtual labs in the browser, marked on the server

Date: 2026-10-13 · Status: accepted

## Context

PRD 7.5 and 11.3 ask for practical labs with a common session model: versioned definitions, a gateway that authorises, launches, expires and ends sessions, bindings to school, learner, course and attempt, limits, and evidence. Untrusted code must never run in the web process. Phase 1 asks for a first science simulation, a programming exercise and a safe networking or security exercise. The platform runs on one shared server with no container host for learner workloads yet, and learners are often on phones and slow connections.

## Decision

* **Lab = catalogue entry + browser simulation.** Each lab is a PHP class (definition, numbers, answer fields, marking) plus `public/assets/labs/<key>.js`. Teachers choose labs from the catalogue; they never supply code that runs on the server (LAB-008). Definitions carry a version; a session started on an older version cannot be submitted.
* **Numbers from a seed, marking on the server.** A session stores a random seed. The lab derives its numbers from it, so every learner gets their own and the server recomputes them when marking. Answer keys the simulation does not need (which emails are phishing, the grades the code should return) never leave the server.
* **Gateway** (`LabGateway`): only enrolled learners on a published course launch; one open session per learner and lesson; 30 launches an hour per learner; a deadline from the lab's time limit plus 2 minutes' grace; every later request re-checks school, learner, status, version, deadline and that the lesson still exists. Submissions take a row lock so one attempt is marked once. Passing completes the lesson through the normal completion rule. `lab:expire` (cron, every 10 minutes) closes stale sessions; the System page warns if it is not running.
* **Learner code runs only in the learner's browser**, in `/labs/runner`: an iframe with `sandbox="allow-scripts"` (opaque origin: no cookies, storage or access to the page), whose CSP is `default-src 'none'`, `connect-src 'none'` and a per-response nonce, and which runs the code in a Web Worker killed after 2 seconds. Only short strings come back. The server marks the outputs against its own reference implementation for test inputs it chose for that session.
* **Evidence** is the learner's answers and small work records (readings, titres, code up to 8,000 characters), capped at 16 KB per session; never secrets or full terminal output (LAB-007).
* **Courses get a track**, school subject or skills, so formal-curriculum and vocational courses can be told apart and filtered.

## Consequences

* Labs cost the server almost nothing and work offline once a page is loaded; the trade-off is that a determined learner can read a simulation's own numbers in the browser (e.g. the hidden resistance). These labs are practice with feedback, not high-stakes exams. High-stakes or real-machine labs (shells, network emulation) need the separate isolated execution service in PRD 10.1, which plugs into the same session model.
* Browsers enforce the sandbox; very old browsers without Web Workers cannot run the code lab and are told so.
* Memory use of learner code is limited only by the browser tab, not by us.
