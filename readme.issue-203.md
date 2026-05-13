# Issue 203 — Update top banner image on wordpress.org page

Parent: 195. Sibling done: 202 (screenshot-1 already replaced).

## Goal

Replace `.wordpress-org/banner-1544x500.png` and `.wordpress-org/banner-772x250.png` — both still show the pre-React (~2024 era) UI mockup with outdated event copy ("Updated plugin 'WooCommerce' to 9.5.2 from 9.5.1", "Uploaded attachment 'cale-01.jpg'", old search-options form).

## Approach

Self-contained HTML mockup → Playwright → PNG.

-   `tests/screenshot/banner-mockup.html` — single file rendering the entire 1544×500 banner: cream panel left + stylized SH log panel right.
-   `tests/playwright/screenshot-banner.spec.js` — captures the HTML at 1544×500 and saves to `.wordpress-org/banner-1544x500.png`.
-   `tests/screenshot/run.sh` — adds a sharp/ImageMagick downscale step producing `banner-772x250.png` (exact 1/2 size).

### Why HTML mockup, not Playground capture

The banner aspect ratio (3.088:1) doesn't match any real wp-admin view, and the wp-admin sidebar chrome doesn't shrink cleanly. We'd have to crop/composite either way — building the whole thing in HTML keeps it pixel-perfect and editable.

## What stays from the current banner

-   Cream/beige background (`#FDF5E6` ish)
-   Logo: clock icon + "Simple History" in dark serif
-   Headline: "Your powerful all-in-one WordPress Activity Log."
-   Subhead: "Stay informed and in control with Simple History."
-   Body: "Track content changes, debug faster, ensure security, troubleshoot client issues – and more!"

## What changes

-   Right panel UI mockup → current React-era Simple History log
-   Event mix → recommended marketing 4-event mix from `.claude/skills/wp-org-screenshots/SKILL.md`:
    1. Failed login from 203.0.113.42 (warning badge, security hook)
    2. Sally updated page "About us" — inline before/after diff (the differentiator)
    3. Alex activated plugin "Yoast SEO" — with View plugin info link
    4. Sally uploaded attachment "team-photo.jpg" — with thumbnail

## TODOs

-   [ ] Sample the cream background hex from the existing banner
-   [ ] Match the serif font (looks like Playfair Display or similar) for headline/body
-   [ ] Build `banner-mockup.html`
-   [ ] Build `screenshot-banner.spec.js`
-   [ ] Add downscale step to `run.sh`
-   [ ] Run, compare output, tune
-   [ ] Update issue 195 once 203 is done (parent closes when 202 + 203 both ship)
