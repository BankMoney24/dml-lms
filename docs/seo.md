# Search, social and security: audit and runbook (update 19)

For **https://dmlschooloftechnology.org**, developed and powered by TridaxLabs (https://tridax.org). Decisions: ADR 0014 (brand and search), ADR 0019 (domain, metadata, public pages).

## 1. What was there before (audit, 14 Oct 2026)

Stack: PHP 8.3 modular monolith, server-rendered PHP templates, MySQL 8, Apache + PHP-FPM on AlmaLinux 9, multi-school (each school found by its verified host). No front-end framework, no build step, strict CSP.

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Domain resolved to the server but was not configured; the school was only on `http://66.29.156.116:8088` | High | Script and config added; needs running on the server (§5) |
| 2 | Canonical, `og:url`, sitemap and robots built from the request's Host header; IP, www and future domain would all be indexable copies | High | Fixed: one canonical address per school, aliases redirect (301) and disallow crawling |
| 3 | HSTS sent with `includeSubDomains` on any HTTPS request | Medium | Fixed: configurable, subdomains off by default |
| 4 | No About, Contact, Admissions, Privacy, Terms, Refund or Safeguarding pages | High (trust, payments, children) | Fixed: school-written pages; nothing goes live with placeholders |
| 5 | Social previews used the square logo (small card), no image alt, no locale, no Twitter title/description | Medium | Fixed: 1200 × 630 share cards per school, course and page, full OG/Twitter set |
| 6 | Structured data: separate blocks without `@id`, course `courseWorkload` defaulted to an invented 1 hour | Medium | Fixed: one `@graph` with stable ids, breadcrumbs, nothing defaulted |
| 7 | Sitemap without `lastmod`; free preview lessons and pages missing | Low | Fixed |
| 8 | Certificate check results (`/verify/<code>`) could be crawled; they show a learner's name | Medium (child privacy) | Fixed: `Disallow: /verify/` (pages were already noindex) |
| 9 | Public "Sign in" button text failed contrast (3.4:1) | Medium (accessibility) | Fixed |
| 10 | Logo images without width, causing a small layout shift | Low | Fixed |
| 11 | Course list skipped a heading level | Low | Fixed |
| 12 | No compression or long caching for text assets | Low (performance on slow networks) | Fixed in `.htaccess` (needs `mod_deflate`, standard on AlmaLinux) |
| 13 | XML: no XML is parsed anywhere (no importers, feeds or office-file parsing); XML is only generated (sitemap) with escaping | — | Nothing to fix; a test parses the sitemap with network access off |
| 14 | XSS: templates escape every value; lesson and page Markdown is escaped and links limited to http(s); JSON-LD uses `JSON_HEX_*`; CSP has no inline script or style | — | Verified by tests (script tags and `javascript:` links in page text) |
| 15 | Open redirects: app redirects are relative only; the new canonical redirect only targets the school's verified primary domain | — | Tested |
| 16 | `MAIL_FROM_ADDRESS` and the phishing lab still used `…technology.com` | Low | Changed to `.org`; check your real mail setup (§6) |

Not changed, on purpose: no analytics or advertising scripts (children use the site; nothing to consent to), no `llms.txt` or AI-specific tags (no evidence they help), no FAQ markup (Google shows FAQ results only for authoritative government and health sites), no invented address, ratings or accreditation.

## 2. What was built

* **Canonical domain** — `app/Modules/Tenancy/CanonicalUrl.php`, `CanonicalHost.php` (middleware), `TenantRepository::primaryHost/setPrimaryDomain`, console `tenant:add-domain … --primary` and `tenant:primary-domain`, settings `PUBLIC_SCHEME`, `CANONICAL_REDIRECT`, `HSTS_MAX_AGE`, `HSTS_INCLUDE_SUBDOMAINS` (`.env.example`), `deploy/apache/dml-lms-domain.conf`, `deploy/server/setup-domain.sh`.
* **Metadata** — `app/Modules/Platform/Seo/PageMeta.php`, used by `resources/views/layouts/app.php`. Controllers pass `pageTitle`, `metaDescription`, `indexable`, and optionally `canonicalPath`, `seoCrumbs`, `jsonLd`, `ogImage(+Alt, Size)`.
* **Structured data** — `app/Modules/Courses/CourseSchema.php` (organisation, website, web page, course).
* **Share images** — `app/Modules/Platform/Seo/ShareCard.php`, `ShareController.php`, fonts in `resources/fonts/` (DejaVu, licence included).
* **Public pages** — `app/Modules/Site/*`, views `resources/views/site/page.php`, `school/website.php`, `school/website_edit.php`, migration `2026_10_14_000016_create_site_pages.sql`. Admin: School → Website.
* **Sitemap and robots** — `app/Modules/Platform/SeoController.php`.
* **Credit** — footers and sidebar in the layout; About page note.

## 3. How it was tested

Automated (run on MariaDB 10 and MySQL 8): `tests/Feature/WebsiteTest.php`, `SeoTest.php` and the rest of the suite — unique titles and descriptions on representative public pages, description length, robots values, one canonical per page, full OG/Twitter set, `en-NG` language and locale, JSON-LD parses and has the expected `@graph` types and ids, breadcrumbs, no invented workload or address, noindex and no structured data on filtered and private pages, canonical on the primary domain (and the temporary port kept), 301s from www, IP and HTTP with the query kept, no redirect of form posts or on the canonical address, robots on aliases, HSTS on/off/subdomains, security headers (CSP, frame-ancestors, nosniff, referrer and permissions policies), page drafts blocked while placeholders remain, publishing and unpublishing, escaping of script tags and `javascript:` links, footer links, sitemap is well-formed XML (parsed with network access off) with only public canonical URLs and ISO `lastmod`, share images are 1200 × 630 PNG, public, cookie-free and refused for drafts or private catalogues, verification codes validated (a pasted meta tag is accepted, injected markup refused), and a crawl of every internal link from the home page with no 404 or 500.

Lab checks on the development server (no compression or caching there, so real numbers will be better): Lighthouse 12, mobile, simulated slow 4G — home, course page and course list scored Performance 99, Accessibility 100 (98 before the heading fix on the list), Best practices 100, SEO 100; LCP 1.8–2.0 s, CLS 0, TBT 10–60 ms. No horizontal scrolling at 320, 360, 390, 768 and 1024 px on the public pages.

Not done from here (needs the live HTTPS site or your accounts): Google Rich Results Test, Schema.org validator on the live URL, PageSpeed Insights field data, Search Console and Bing, Facebook Sharing Debugger, LinkedIn Post Inspector. The development environment could not reach dmlschooloftechnology.org directly.

## 4. tridax.org (read-only look, no access to its code)

The home page has a title ("Tridax - AI-Native ERP Platform | Enterprise Software Development"), a meta description and an H1. `robots.txt` and `sitemap.xml` could not be fetched from here (error responses), so their presence is unconfirmed. The site calls itself "Tridax" while the contact email and Instagram use "tridaxlabs"; several social links are generic handles (`twitter.com/tridax`, `facebook.com/tridax`) worth checking. Nothing on tridax.org was changed, and DML pages never use it as their canonical domain.

## 5. Deploy

1. Install update 19 as usual (migration 000016 runs on activate).
2. On the server, as root: `bash /var/www/dml-lms/current/deploy/server/setup-domain.sh dmlschooloftechnology.org <email>` — see `docs/deployment.md`. It backs up first and prints how to undo.
3. Sign in at https://dmlschooloftechnology.org, open School → Settings and check the contact email and website (they may still say `.com`).
4. School → Website: write and publish the pages that apply to you.

## 6. Steps only you can do

* Run the domain script (§5), then retire the `:8088` address when ready.
* Google Search Console and Bing Webmaster Tools: add `https://dmlschooloftechnology.org` (a Domain property with a DNS TXT record covers http, https and www), or paste the meta-tag code under School → Website; submit `/sitemap.xml`; inspect the home page and one course.
* Test a shared link in Facebook Sharing Debugger and LinkedIn Post Inspector.
* Email: send mail as `@dmlschooloftechnology.org` only after adding SPF and DKIM for that domain with your mail provider; then set `MAIL_FROM_ADDRESS`.
* Have the Privacy, Terms, Refund and Safeguarding pages checked by someone who knows the law that applies to you (for example the Nigeria Data Protection Act 2023) before publishing.
* Google Business Profile: only if the school has a real address where it meets learners; never a virtual office.
* Official social profiles: enter them under School → Website so they appear on the contact page and in `sameAs`.

## 7. Known limits

* Share images need `php-gd` with FreeType (the domain script installs it); otherwise the brand image is shown.
* The tenant logo is served as uploaded (no WebP or resized copy yet); Lighthouse estimates about 20 KB could be saved.
* Search Console and analytics data are not shown inside the platform.
* `includeSubDomains`/preload for HSTS are off until every subdomain serves HTTPS.

## 8. Ongoing checklist

Monthly:
* Search Console → Pages: indexed vs excluded; fix anything "Crawled – not indexed" that should be indexed by improving the page.
* Search Console → Sitemaps: last read date and count match the courses and pages you have published.
* Core Web Vitals report (field data) for mobile: LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1.
* Enhancements: breadcrumbs and any structured-data errors.

When publishing a course: a clear title, a summary of one or two sentences, outcomes, level and duration; mark one reading as a free preview.

When the school changes: update School → Settings (contact details, tagline, logo) and the About and Admissions pages; the structured data and share images follow automatically.

Quarterly: review the policy pages and their "Last updated" dates; check the certificate renewal (`certbot renew --dry-run`); run `phpunit` and a Lighthouse pass on the home page and one course.
