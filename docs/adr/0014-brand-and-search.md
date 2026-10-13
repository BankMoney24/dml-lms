# ADR 0014: DML Technologies brand, and what search engines see

Date: 2026-10-13 · Status: accepted

## Context

DML Technologies supplied its logo in three versions: blue and black on white (with the line "Developing Leaders Through Specialized Education"), white on black, and white with red on navy ("Learn, Build, Grow"). The platform is powered by TridaxLabs. Public pages also need to be found and to preview well when shared.

## Decision

* **Colours.** Light surfaces use the logo's blue and black: primary `#1670ad` (the logo blue darkened to pass WCAG AA for text and button labels), ink `#12161f`. Done and progress use the logo's sky blue `#23a1de`. DML red is the accent, kept for what needs attention first: the active menu item, urgent to-dos, the main button on navy panels. Dark brand panels (the welcome hero, sign-in, "continue where you stopped") use the logo's navy `#161a25`. Amber stays for warnings only, so red never has to mean both "brand" and "problem".
* **The pixel.** The logo's squares replace the old round nodes: course board strips, lesson traces, set-course lists and markers are square. A small pixel cascade is the platform mark when a school has no logo, and the favicon.
* **Themes.** "DML blue" is a school theme and the default for new schools; existing schools keep their colour. `bin/console school:brand <slug> --logo=… --theme=dml --tagline="…"` sets a school's logo, theme and tagline in one step; the DML logo ships in `public/assets/brand/`.
* **Words.** The welcome page's three steps follow the brand line: Learn, Build, Grow. Every page footer credits "DML Technologies learning platform, powered by TridaxLabs".
* **Search.** Every page has a description and Open Graph tags (title, description, image: the school's logo, or the DML share image). Only public pages are indexable, with a canonical address: the welcome page, and the course list and course pages when the school lets visitors browse them. Everything else says `noindex, nofollow`. Course pages carry schema.org `Course` data (provider, level, outcomes, workload, free or paid with price); welcome pages carry `EducationalOrganization`. Per-school `/robots.txt` keeps crawlers out of private areas and points to `/sitemap.xml`, which lists only public pages. Structured data is a JSON data block, which the strict CSP does not execute.

## Consequences

* Schools other than DML still choose their own colours; the accent red and the pixel shapes are platform-wide.
* Search engines need the school's domain to be reachable; submit `https://<domain>/sitemap.xml` in Google Search Console once the domain is on HTTPS.
