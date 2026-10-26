# Design system

The platform has one stylesheet (`public/assets/app.css`, no build step) and one small script (`public/assets/app.js`). Every page works without the script; it adds conveniences on top. This page records the rules that keep new screens consistent. When a rule here and the stylesheet disagree, fix one of them in the same change.

## Brand and character

DML School of Technology: blue and black on light surfaces, navy with red on dark ones. The logo's square "pixels" are the unit of progress everywhere: board strips on course cards, traces on the sign-in panel, the lit squares on the "Ready for learners?" list. Squares are lit (sky blue) when something is done and hollow when it is still to do.

Avoid: gradients as decoration, glass effects, drop shadows on every card, all-caps labels (the only exception is the certificate kicker, which imitates print), and a different radius on every component.

## Tokens (`:root` in app.css)

| Group | Tokens | Use |
|---|---|---|
| Surfaces | `--paper` (page), `--surface` (cards, tables, inputs), `--line`, `--line-strong` | |
| Text | `--ink`, `--ink-soft` | `--ink-soft` passes 4.5:1 on paper and surface, in both modes |
| Brand | `--primary` #1670ad, `--primary-soft`, `--primary-ink`; `--lamp` #23a1de (done and progress); `--accent` DML red (what needs attention first, used sparingly); `--brand-navy` #161a25 | |
| States | `--warn`, `--danger`, their `-soft` backgrounds, `--focus` | |
| Type | Plus Jakarta Sans (self-hosted, variable 200–800); steps `--step--1` 0.875rem to `--step-4` 2.75rem; body 1.0625rem | |
| Space | `--space-1` 0.25rem … `--space-7` 3rem | Use the scale; no one-off pixel gaps |
| Radius | `--radius-s` 8px (inputs, small controls), `--radius-m` 12px (cards, tables, notices), `--radius-l` 16px (large panels); buttons are fully round | |
| Layout | `--sidebar-w` 16rem, `--appbar-h` 3.75rem, `--measure` 70ch for reading text | |

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

## Behaviour (app.js)

* Forms with a text area (or `data-unsaved`) warn before the page is left with unsaved writing. Quiz forms keep their own on-device drafts instead.
* Menus built on `<details data-menu>` close on Escape and on a click elsewhere.
* Search suggests as you type; Enter still goes to the full results page.
* The connection banner says plainly when the device is offline.
* Nothing animates unless the person's device allows motion (`prefers-reduced-motion`).

## Responsive rules

Checked at 360, 390, 430, 768, 820, 1024, 1280 and 1440px wide (`tests/browser/ui_check.py`). No page may scroll sideways; wide tables scroll inside their own frame. Below 48rem the sidebar becomes a slide-in menu. Touch targets are at least 24px (WCAG 2.2, 2.5.8), and main actions 44px.

## Accessibility baseline

WCAG 2.2 AA. Every page has one `h1`, a skip link, labelled landmarks, visible focus (3px `--focus` outline), text contrast of at least 4.5:1 in both modes, and no information carried by colour alone. axe-core is run on every page in the checklist (see `docs/overhaul-checklist.md`); an automated scan is a floor, not proof, and screen-reader passes are listed there as still to do.
