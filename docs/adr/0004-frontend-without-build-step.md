# ADR 0004 — Hand-written CSS and vanilla JS instead of Tailwind/HTMX/Alpine for now

**Status:** accepted · 2026-10-12 · revisits PRD §10.1 "Frontend"

## Context
The PRD suggests compiled Tailwind, HTMX and Alpine.js, and sets a ≤150 KB compressed budget for the learner shell (§8.2). The first slice has a handful of pages and no partial-update needs.

## Decision
* One CSS file with design tokens (light and dark), one ~1.4 KB (compressed) script for progressive enhancement, system fonts.
* No Node build step in CI or on the server.
* CSP allows only same-origin scripts and styles, with no inline code.

## Consequences
* Current shell: ~5 KB compressed; CI fails if it exceeds 150 KB.
* When a feature needs partial page updates (grading queue, live progress), add HTMX as a vendored, versioned file under `public/assets/` and record it here. Tailwind can be introduced if the component count makes hand-written CSS costly.
