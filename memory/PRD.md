# PRD — Lucy Play (Laravel Casino Platform)

## Original Problem Statement
"i need the admin page UI to look like the font the look but in best and modern way you can make the Best UI also the FrontEnd" — reference design: https://dashboard.abyssinia.games/ (screenshot of casino admin dashboard provided). Existing Laravel app served at http://127.0.0.1:8000/admin.

## Architecture
- Laravel 11 (PHP 8.2) monolith with Blade templates — NOT the default FastAPI/React stack
- DB: SQLite at /app/database/database.sqlite (was MySQL in prod config)
- Served via supervisor: program `backend` → `php artisan serve` port 8000, program `frontend` → port 3000 (for platform preview ingress)
- Admin views: /app/resources/views/admin/*, layout: /app/resources/views/layouts/admin.blade.php
- Admin styles: /app/public/css/lucy-admin.css | Player styles: /app/public/css/lucy-play.css

## Environment Setup Notes (re-run after pod restart if needed)
- apt install php8.2 + extensions (bcmath, gmp, sqlite3, mbstring, xml, curl, zip, gd, intl); composer at /usr/local/bin/composer
- Supervisor conf.d entries were patched to run artisan serve (outside /app — may need re-patching after restart)

## What's Been Implemented (Jun 2026)
- [x] Environment setup: PHP 8.2, Composer deps, SQLite DB, migrations + seeders
- [x] Full admin UI redesign matching abyssinia.games reference, modernized:
  - Design system in lucy-admin.css (rewritten, all class names preserved): Instrument Sans + Bricolage Grotesque fonts, indigo primary, pastel gradient metric cards, soft layered shadows, pill range tabs, entrance/hover micro-animations
  - FontAwesome 6 icons replacing unicode glyphs in sidebar + dashboard
  - Larger readable typography (was 8-10px, now 12.5-14px base)
  - data-testid attributes on nav + metric cards
- [x] Testing: iteration_1 — 100% frontend pass (all 19 admin routes, CRUD flows, filters, collapse, logout)

### Iteration 2 (Jun 2026) — Images on frontend + form redesign
- [x] `php artisan storage:link` + Game::image_source uses asset('storage/...') — admin-uploaded game artwork now displays on player lobby cards (.game-cover, hover zoom, overlay, label badge with admin-set color)
- [x] games/show.blade.php coming-soon art shows uploaded image
- [x] Admin game edit form rewritten: sectioned pro layout (Artwork/Catalogue/Display & badges/Integration/Publishing) with toggle switches, sticky action bar
- [x] Create agent form rewritten: Identity/Security/Commission sections
- [x] "+ Add New" popover forms got titled headers; toggle-switch + multi-select styling in lucy-admin.css
- [x] Testing: iteration_2 — 37/37 pass (image upload E2E, form persistence, label badge, lobby regression, bet placement)
- Note: lucky-dice has demo artwork at storage/app/public/games/test-lucky-dice.png (replace with real art via admin)

## User Personas
- Platform admin (Lucy Administrator) — manages members, agents, casino engine, promotions, reports

## Backlog
- P1: Extract duplicated game-card markup in lobby.blade.php into a Blade component
- P2: Styled date-picker component instead of native HTML5 inputs
- P2: Add charts (bets over time) to dashboard
- P2: Whitelist module slugs in /admin/module/{module} placeholder route
- P2: Orphaned upload file cleanup job for game images

---
## 2026-02-16 — Admin Edit Game & Casino Engine list redesign

### Completed
- Rewrote `/app/resources/views/admin/game-engine/edit.blade.php` to match the abyssinia.games/casino/game/1 reference:
  - Single unified white card, no more multi-section icon headers
  - Big centered dashed drag-drop logo zone (label "Logo")
  - 3-column grid rows: Name/Slug/Description → Top Game/Demo Support/Free Bet → Disabled/Label/Label BG → Provider/Category/Order → Provider Game ID/Launch Code/Local Agent → Tag/Fallback Art/Fallback Symbol → Lobbies (full) → Crash/Content server disabled
  - Title "Edit Game" left, "Go back" pill + red icon-only delete button top right
  - Footer with Cancel (link) + dark "Save changes" button
- Appended new Abyssinia-style CSS block to `/app/public/css/lucy-admin.css` (larger card, pill buttons, cleaner toggles, hover states)
- Modernized `/app/resources/views/admin/game-engine/index.blade.php` table styling (rounded 56px thumbnails, uppercase muted column headers, pill-shaped Edit action, subtle hover)
- Fixed preview mixed-content issue: added `URL::forceScheme('https')` in `AppServiceProvider::boot()` and `trustProxies(*)` in `bootstrap/app.php` so login sessions no longer break across preview subdomains

### Backlog (P1/P2)
- P1: Inline row-toggle for top_game/disabled (no navigate to edit)
- P1: Bulk action bar for multi-game disable/delete
- P1: Extend the same "clean form card" design to remaining admin forms (Agent create already redesigned; Settings, Payment gateways, Promotion banners, Categories, Providers, Lobbies still use older popover forms)
- P2: Bets-over-time GGR chart on dashboard
- P2: Drag & drop image upload directly in game table rows
