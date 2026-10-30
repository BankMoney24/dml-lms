# Design system

The platform has one stylesheet (`public/assets/app.css`, no build step) and one small script (`public/assets/app.js`). Every page works without the script; it adds conveniences on top. This page records the rules that keep new screens consistent. When a rule here and the stylesheet disagree, fix one of them in the same change.

## Brand and character

DML School of Technology: blue and black on light surfaces, navy with red on dark ones. The logo's square "pixels" are the unit of progress everywhere: board strips on course cards, traces on the sign-in panel, the lit squares on the "Ready for learners?" list. Squares are lit (sky blue) when something is done and hollow when it is still to do.

Avoid: gradients as decoration (the only ones are the faint glow on the dark hero panels and the shade at the foot of a course cover), blur except on bars that sit over content (the top bar and the phone tab bar), heavy shadows (cards get one soft `--shadow-card`; only floating things get `--shadow-2`), all-caps labels (the only exception is the certificate kicker, which imitates print), and radii outside the scale.

## Tokens (`:root` in app.css)

| Group | Tokens | Use |
|---|---|---|
| Surfaces | `--paper` (page), `--surface` (cards, tables, inputs), `--line`, `--line-strong` | |
| Text | `--ink`, `--ink-soft` | `--ink-soft` passes 4.5:1 on paper and surface, in both modes |
| Brand | `--primary` #1670ad, `--primary-soft`, `--primary-ink`; `--lamp` #23a1de (done and progress); `--accent` DML red (what needs attention first, used sparingly); `--brand-navy` #161a25 | |
| States | `--warn`, `--danger`, their `-soft` backgrounds, `--focus` | |
| Type | Plus Jakarta Sans (self-hosted, variable 200–800); fluid steps with `clamp()`: `--step-0` 1–1.0625rem (body), `--step-3` 1.75–2.375rem (page titles), `--step-4` up to 3.25rem; headings tighten letter-spacing (−0.025em for h1) | Form fields are 16px so iPhones do not zoom |
| Space | `--space-1` 0.25rem … `--space-7` 3rem | Use the scale; no one-off pixel gaps |
| Radius | `--radius-xs` 6px (badges, square like the logo's pixels), `--radius-s` 10px, buttons and fields 12px, `--radius-m` 14px (notices), `--radius-l` 18px (cards, panels, tables), `--radius-xl` 26px (the dark hero panels) | Larger things get larger corners |
| Layout | `--sidebar-w` 16.5rem, `--rail-w` 5.75rem (tablets), `--appbar-h` 4rem (3.5rem on phones), `--tabbar-h` 4.25rem, `--gutter` 1–2.5rem fluid, `--measure` 70ch for reading text | |
| Elevation | `--shadow-1` (hairline), `--shadow-card` (cards, panels, tables), `--shadow-2` (things that float: menus, the drawer, hovered cards); `--ring` is the focus halo on buttons and fields | |

Dark mode follows the device unless the person picks Light or Dark in the account menu (`data-appearance` on `<html>`). Every token above is redefined for dark mode; components use tokens, not raw colours. The seven school colour themes (`.theme-*`) only change `--primary` and its pair, and each is checked for contrast in both modes.

## Components

| Component | Markup | Notes |
|---|---|---|
| Button | `.button`, `.button-quiet`, `.button-danger`, `.button-small`, `.button-lamp`, `.linklike` | At least 2.5rem tall (44px for the main size). The words say what happens ("Publish", "Add person"), never "Submit" |
| Busy button | added by app.js: `aria-disabled="true"` on the form's buttons, `.is-busy` on the one pressed | A turning ring; a still, faded ring when reduced motion is on. A second tap does not send the form again. Restored when the person returns with Back, or after 15 seconds |
| Field | `$v->field(name, label, options)` | Label above, hint under the label, error under the box, `aria-describedby` wired. Password fields get Show / Hide and a Caps Lock warning automatically (`'reveal' => false` to opt out) |
| Error summary | `$v->errorSummary($errors)` | At the top of a form that failed; each message links to its field |
| Notice | `.notice`, `.notice-ok`, `.notice-done`, `.notice-err`; flash messages use `.toast` | Status messages use `role="status"`, errors `role="alert"` |
| Badge | `$v->badge(text, tone)` | Tones: neutral, ok, done, draft, danger |
| Breadcrumbs | `$v->breadcrumbs([...])` | Links are at least 24px tall |
| Tabs | `school/_tabs.php`, `platform/_tabs.php` | Links with `aria-current="page"`; they scroll sideways on phones |
| Table | `.table` inside `.table-wrap` | The wrapper scrolls sideways when needed and then becomes keyboard-focusable (app.js). `.table-stack` turns rows into stacked cards on phones |
| Sortable table | `<table data-sortable>`; `data-sort="…"` on a cell gives the value to sort by (dates, scores) | Headings become buttons with `aria-sort`; the order is only for this view and is not saved |
| List tools | `.list-tools` GET form with `.list-tool` fields, `data-autosubmit` | Find, filter and order above long lists; a changed select reloads the list; the button stays for typed searches |
| Pager | `App\Core\View\Pager` + `partials/pager.php` | First, last and two pages either side; links keep the filters; 44px targets |
| Segmented switch | `.segmented` | Appearance in the account menu, data saver in the classroom |
| Choice | `.choice` label with a radio or checkbox | Whole row is the target (2.75rem tall) |
| Board / pixels | `.board`, `.board-node.is-lit`, `partials/pixels.php` | Progress shown as squares; always with a text equivalent |
| Launch list | `.launch` | Lit square = done, hollow = to do; each to-do links to where it is fixed |
| Focus layout | `layouts/focus.php` with `focusClass`, `focusScript`, `exitHref`, `exitLabel` | Full screen, no sidebar, one exit link: the CBT exam room and the live quiz play screen (ADR 0042) |
| Answer tiles | `.lq-0` … `.lq-3` (with `.lq-letter`), colours `--tile-0` … `--tile-3` | Live quiz answers. Always lettered A–D as well as coloured, white text checked for contrast in both modes; our own colours and no shapes, so they are not mistaken for another product's |
| Exam palette | `.cbt-palette` buttons | One per question: answered, flagged and current shown by fill, outline and a flag mark, not by colour alone |

## Behaviour (app.js)

* Forms with a text area (or `data-unsaved`) warn before the page is left with unsaved writing. Quiz forms keep their own on-device drafts instead.
* Menus built on `<details data-menu>` close on Escape and on a click elsewhere.
* Search suggests as you type; Enter still goes to the full results page.
* In "I have paid the school", the amount follows what the payment is for (`form[data-claim-amounts]`) until the learner types an amount of their own.
* The connection banner says plainly when the device is offline.
* Nothing animates unless the person's device allows motion (`prefers-reduced-motion`).

## Responsive rules

The signed-in shell has three forms (ADR 0040):

| Screen | Width | Navigation |
|---|---|---|
| Phone | under 48rem (768px) | Top bar with the school, and the account menu; a **bottom tab bar** with Home, Courses (Teach for staff who do not learn), Search, Alerts and Menu. Menu opens the full list as a drawer. Pages with the classroom, the network builder or the code studio hide the tab bar so the work has the whole screen. |
| Tablet (iPad) | 48–74.99rem | A navy **rail** of icons with short labels (`sideLink(..., $short)`), the full label kept for screen readers; the top bar keeps search and the menu button, which opens the full list over the rail. |
| Desktop | 75rem and up | The full navy sidebar. |

Rows of courses on the home pages (`$listScroll`) swipe sideways on phones instead of stacking; the course list itself always stacks. Tables marked `.table-stack` turn into one card per row under 40rem. Every page is checked for sideways scrolling at 360–1440px.

## Words on the page

Write "learner" in templates. A school that calls them students, pupils or trainees sees its own word (ADR 0041): the page is rewritten after rendering, in the text and in the attributes people read (placeholder, title, aria-label, alt, data-label, submit buttons, the page description). Mark anything people wrote themselves, so it is left as written:

* Long text: wrap it in `.prose` (lesson text, posts, site pages) or print it with `$v->paragraphs()`, which marks its paragraphs.
* Short values: put `data-own-words` on the element, as the setup review does for answers.
* Code in `code`, `pre`, `textarea`, `script` and `style` is never touched, and neither are links, form values or file names such as `learners.csv`.

## Accessibility baseline

WCAG 2.2 AA. Every page has one `h1`, a skip link, labelled landmarks, visible focus (3px `--focus` outline), text contrast of at least 4.5:1 in both modes, and no information carried by colour alone. axe-core is run on every page in the checklist (see `docs/overhaul-checklist.md`); an automated scan is a floor, not proof, and screen-reader passes are listed there as still to do.
