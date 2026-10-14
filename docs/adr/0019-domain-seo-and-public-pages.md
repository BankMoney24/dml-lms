# ADR 0019: Canonical domain, page metadata and the school's public pages

Date: 2026-10-14 · Status: accepted

## Context

dmlschooloftechnology.org (and www) now point at the server. Until now the school was reached on a temporary IP address and port, every address served the same pages, and canonical links, social previews and the sitemap were built from whatever host a request used. The school also had no About, Contact or policy pages, and the brief asks for search, social and AI-search readiness without inventing facts about the school.

## Decision

* **One canonical address per school.** A school's primary verified domain (`tenant_domains.is_primary`) is its canonical address, on `PUBLIC_SCHEME` (https once a certificate exists). Canonical links, Open Graph URLs, structured data, the sitemap, robots.txt and links in emails are built from it, never from the Host header. On the primary host itself the request's port is kept, so the temporary `:8088` address keeps working until the switch. With `CANONICAL_REDIRECT=true`, GET/HEAD requests on aliases (www, the IP, plain HTTP) get a 301 to the same path and query on the canonical address; form posts are not moved. robots.txt on an alias disallows everything. `bin/console tenant:add-domain … --primary` / `tenant:primary-domain` set it; `deploy/server/setup-domain.sh` does the whole switch with backups and checks.
* **HSTS** is sent on HTTPS only, for a year, without `includeSubDomains` unless `HSTS_INCLUDE_SUBDOMAINS=true` (other services on the domain, such as webmail, may still use HTTP). `HSTS_MAX_AGE=0` turns it off.
* **One metadata builder** (`PageMeta`) for every page: title (page – school, clipped), description (≤160 characters), robots, canonical, `og:*` including image alt, size and locale, Twitter card tags, `html lang` from the school's locale, search-console verification tags (home page only) and a single JSON-LD `@graph`. Private pages always get `noindex, nofollow` and no canonical, image or structured data. Filtered and paged lists are noindex.
* **Structured data** uses stable `@id`s: `EducationalOrganization` (name, URL, logo, slogan, email, phone, contact point, `sameAs` only for profiles the school enters), `WebSite`, `Course` (provider by `@id`, level, outcomes, duration and price only when set), `AboutPage`/`ContactPage`/`WebPage`, and `BreadcrumbList`. No address, rating, review, accreditation or date is added unless the school has entered and shown it. No FAQ markup (Google limits FAQ results to authoritative government and health sites).
* **Share images.** `/share/site.png`, `/share/courses/{slug}.png`, `/share/pages/{page}.png`: 1200 × 630 PNGs drawn with GD (school logo, title, the logo's pixels), cached on disk until the content changes, public and cookie-free; only for published, public content. Without GD/FreeType the static brand image is used. Fonts: DejaVu Sans (bundled with its licence).
* **The school's public pages** (`site_pages`): About, Admissions, Contact, Privacy, Terms, Refunds, Safeguarding, written by owners/admins under School → Website. Starter drafts write out only what the platform knows to be true (what data it stores, the single session cookie, Paystack handling card details, how guardians are linked) and mark every school-only fact with `[[double brackets]]`. A page cannot be published while a placeholder remains, without a summary, or (Contact) without contact details. Published pages appear in the public navigation and footers, the sitemap (with `lastmod`) and the home page (About). Page text is Markdown, escaped; links only to http(s).
* **Developer credit**: "Developed and powered by TridaxLabs" linking to https://tridax.org, in the footers and sidebar, and an "About this website" note on the About page. TridaxLabs is never named as the school or its operator, and is not repeated in titles or descriptions.
* **Sitemap** lists the home page, published pages, and (when the catalogue is public) the course list, courses and free previews, with `lastmod` from real content changes. robots.txt disallows signed-in areas and certificate results (`/verify/`, which carry names) and keeps assets crawlable.
* **Performance**: gzip for text types, a year's caching for versioned styles and scripts, logo width/height attributes against layout shift, correct heading order on the course list.

## Consequences

* Moving a school to a new domain is one console command plus a certificate; old addresses keep working and redirect.
* A school that never publishes its pages simply has none; nothing invented appears.
* Share images need `php-gd` with FreeType on the server for the drawn cards; otherwise the brand image is used.
