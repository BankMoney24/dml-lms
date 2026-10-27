# ADR 0034: Blog, programmes page, FAQ and careers

Date: 2026-10-27 · Status: accepted

## Context

The school asked for the pages a full organisation's website has (a blog among them) and for its formal and informal learning to be explained to visitors.

## Decision

### Blog

* `blog_posts` (one school per row, like every school table): title, summary, Markdown text, kind (news, event, story, guide), draft or published, author, first published date.
* School → Website → Blog: write, preview, publish, take off, delete (drafts only). A post cannot go live with [[gaps]] or a summary under 50 characters. Only `school.manage` may write.
* The address (`/blog/{slug}`) is made from the title and fixed once the post is published, so shared links keep working.
* Public: `/blog` (newest first, the newest leads; filter by kind; 12 a page), `/blog/{slug}` with BlogPosting data and a generated share image (`/share/posts/{slug}.png`), and an RSS 2.0 feed at `/blog/feed.xml` (cookie-free). Published posts are in the sitemap. `/blog` answers 404 until a post is published; then the menu, footer and page head (feed link) show it.
* Starter drafts (School → Website → Blog → Add starter drafts, or `bin/console blog:starter-posts <school>`): a welcome post, learning with little data, how to spot a payment scam, and a post about everyday courses when the school has them. They describe only what the platform does and what the school's settings say. The one policy statement they make (the school never asks for a card PIN, OTP or banking password) is shown before they are added. They are never published automatically.

### Programmes (`/programmes`)

* The school's visible courses grouped by track, ordered from most structured to most free: School subjects (formal learning), Skills for work (structured training), Everyday learning (informal learning), then any without a track.
* Each group has a short list of facts built from data: levels and calendar (formal), the school's word for a group, whether sign-up is open, whether open courses exist, cost worked out from the courses' prices, certificates if switched on. Platform abilities are written as "can" (teachers can run live lessons), not as promises about every course.
* Visitors to a school whose course list is for members see only open courses here too. With no visible courses the page answers 404 and is not linked.

### FAQ and careers

* Two more school pages: Questions and answers (`/faq`, FAQPage with Question/Answer data taken from its `##` headings) and Careers and teaching (`/careers`). Both have starters with [[gaps]] and full drafts.
* The FAQ full draft answers from settings (how to join for closed, code or open sign-up; costs and payment; offline saving; certificates; parents; password; help). The careers full draft makes two listed choices: it says there are no advertised vacancies at present, and asks for practical experience and a background check before working with under-18s. It links existing members to `/join/teach`.

### Footer

* The public footer is four labelled columns, each a `nav`: Learn, School (pages and contact details), Policies (policy pages and accessibility), Platform (developers and security reports).

## Consequences

* A school can publish news without touching the server. Posts speak for the school; the editor reminds staff about permission before naming learners.
* The programmes page changes as courses are published or retracked; nothing on it needs separate upkeep.
