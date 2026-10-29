# ADR 0040: Interface overhaul for phones, iPads and desktops

Date: 2026-10-29 · Status: accepted

## Context

The school asked for an interface that is more beautiful, modern and responsive, "iPad and mobile, let alone desktop". An audit at 390, 820 and 1440px found:

* Phones and iPads both got the phone layout. An iPad in portrait had no sidebar, only a hidden menu, and phones had no persistent navigation.
* Course rows on the home pages made phone pages very long (over 7,000px).
* Type was small and flat, and cards, panels and tables each had slightly different corners, borders and shadows.
* Pill-shaped buttons and small, low-contrast "Subscribe" chips made targets feel tiny.

## Decision

* **One design layer** over the existing stylesheet (no build step, no new requests):
  * Calmer tokens: cooler paper, a separate inset surface, softer lines and darker ink.
  * Fluid type with `clamp()`, and tighter heading letter-spacing.
  * A radius scale where larger things get larger corners, and three elevations (hairline, card, floating) plus a focus ring.
  * 44px buttons with 12px corners, a tonal secondary button, and 46px fields at 16px text.
  * Quieter tables (inset header, row hover, links without underlines inside cells), square-cornered badges, and a refined sidebar and app bar.
* **A shell per screen size:**
  * A bottom tab bar on phones: Home, Courses or Teach, Search, Alerts with the unread count, and Menu.
  * A navy icon rail with short labels on iPads, with the full label kept for screen readers.
  * The full sidebar on desktops.
  * The drawer opens from either the tab bar's Menu or the top bar.
  * Rooms, the network builder and the code studio hide the tab bar.
* **Course cards:**
  * Covers are tinted by track: deep blue for school subjects, navy for skills, deep teal for everyday learning, so rows are no longer a wall of the same navy.
  * The "Subscribe" chip becomes a tonal button.
  * Home-page rows swipe sideways on phones, as a labelled, focusable region.
* **Public site:** a sticky, translucent top bar, an accent underline on the current menu item, and a hero search that stays white in dark mode.
* The pixel motif stays the one recurring brand element: square markers, square progress nodes, square badges and the square avatar.

## Consequences

* Checks:
  * All 417 tests pass on MariaDB and MySQL 8.
  * axe finds no WCAG 2.2 AA problems in light or dark, across visitor, learner, teacher and administrator pages at 390, 820 and 1440px.
  * The existing browser checks (no sideways scroll at 360–1440px, script behaviours) pass.
* Further screens can adopt the tokens gradually. The older rules above the layer stay until each is cleaned up, so the stylesheet is larger than it needs to be for now.
