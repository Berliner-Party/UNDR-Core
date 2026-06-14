# Developing an UNDR event site

> Drop a copy of this file into each site repo (HEAT / CAGE / UNLEASHED / …) — e.g.
> as `AGENTS.md` (Claude Code reads it automatically) or `DEVELOPMENT.md`. The
> master lives in `UNDR Core/docs/SITE-DEVELOPMENT.md`; edit it there and re-copy.

This repo is **one brand site** in the UNDR family. It is a self-contained PHP 8.1
site (vanilla JS, plain CSS, **no build step**, Apache/PHP) that renders event pages
from a local cache, which is synced from the central **UNDR backend**. All the code
that every brand shares comes from the **`undr/core`** Composer package.

---

## 1. How the whole thing fits together

```
            ┌─────────────────────────────────────────────┐
            │  UNDR backend  (undr.zone)                   │
            │  REST API + MCP server + admin portal        │   ← source of truth for
            │  brands / events / venues / translations     │     ALL event content
            └───────────────┬─────────────────────────────┘
                            │  GET /api/v1/brands/<brand>/events?lang=…
              php bin/sync.php (cron, every minute, locked)
                            ▼
        this repo:  .cache/undr/events.<lang>.json   (gitignored)
                    public/media/…                    (gitignored, mirrored flyers)
                            │  read at render time
                            ▼
        public/index.php → start.php/pages/* → HTML
                            ▲
        ┌───────────────────┴───────────────────┐
        │  undr/core  (Composer package)         │
        │  • Sync engine (bin/sync uses it)      │
        │  • View helpers: h() t() load_events() │
        │  • undr-modal.js / undr-tickets.js     │
        │  • undr-base.css (--undr-* tokens)     │
        └────────────────────────────────────────┘
```

**The site survives the backend being down.** Sync is cache-first: if the API is
unreachable, the last-good cache keeps serving; a brand-new deploy before the first
sync shows a graceful "coming soon" state, never an error.

---

## 2. First-time local setup

Prerequisites: **PHP 8.1+** and **Composer**. For local dev you also need the
sibling **`UNDR Core`** checkout next to this repo (the `composer.json` references it
as a `path` repository at `../UNDR Core`).

```bash
composer install                 # pulls undr/core + runs the asset publish step
php bin/sync.php                  # pull events once into .cache/undr/ (optional)
php -S 127.0.0.1:8000 -t public public/index.php
# open http://127.0.0.1:8000  (German at /de)
```

`composer install` runs `vendor/undr/core/scaffold/publish.php`, which **copies**
`undr-base.css` + `undr-modal.js` + `undr-tickets.js` into `public/assets/`. They are
gitignored (regenerated artifacts) — never edit them in `public/assets/`.

---

## 3. What is shared vs. what is brand-local

**Shared — lives in `undr/core`. Do NOT edit it inside `vendor/`; edit the `UNDR Core`
checkout and `composer update`.**

| Concern | Provided by Core |
|---|---|
| Event-data sync | `Undr\Core\Sync\{UndrSync,SyncResult,Cli}`, `Http\{UndrHttp,UndrResponse}` |
| Escaping / prose | `h()` `attr()` `nl2br_safe()` `prose_html()` |
| i18n | `i18n_boot()` `current_lang()` `t()` `seo_clip()` + `Undr\Core\View\Catalog` |
| Dates | `loc_weekday_short/month_short/short_date/weekday_upper/doors_range/tix_datetime()` |
| Events (generic) | `load_events()` `undr_filter_sort_cap()` `undr_cache_dir()` `undr_read_json()` `asset_renderable()` `asset_abs_url()` `lineup_set_artists()` `event_dt()` `lowest_price()` |
| Modals (JS) | `assets/js/undr-modal.js`, `undr-tickets.js` (configured per brand, see §6) |
| Design tokens (CSS) | `assets/css/undr-base.css` — the `--undr-*` contract + `.sr-only` |
| Per-site config holder | `Undr\Core\Site` |

**Brand-local — lives in THIS repo. Edit freely.**

| Concern | Where |
|---|---|
| Brand-divergent helpers (JSON-LD, ticket links, .ics, timetable, ticket phases, FAQ, display-name…) | `public/lib/events.brand.php` |
| Brand stylesheet | `public/assets/<brand>.css` |
| Brand-unique JS (heat-trail / cage-motion / chains / newsletter / share / undr-more …) | `public/assets/*.js` |
| Views / templates | `public/start.php`, `public/pages/*.php`, `public/lib/layout.php`, `event-templates.php` |
| Routing | `public/index.php` |
| Sync config | `config/undr.php` |
| UI strings + locale tables | `public/lang/en.php`, `de.php` |

> **Important:** the brand-divergent helpers (`build_event_jsonld`, `primary_ticket_link`,
> the `.ics` builder, etc.) intentionally stay per-brand. They have evolved differently
> per brand (CAGE has AggregateOffer/FAQ; UNLEASHED has price-gated offers + mainEntityOfPage;
> HEAT prefers longDescription + derives the rausgegangen widget). **Do not "unify" them** —
> that would change a brand's rendered SEO output.

---

## 4. How a page boots (the contract)

Each entry view does, near the top:

```php
require dirname(__DIR__) . '/vendor/autoload.php';   // Core helpers + events.brand.php (autoloaded)
\Undr\Core\Site::configure(['publicDir' => __DIR__, 'brand' => '<brand>']);
$lang = $lang ?? 'en';
i18n_boot($lang);                                    // loads public/lang/<lang>.php into Catalog
```

After that, every template helper works exactly as before: `h()`, `t('key')`,
`loc_short_date($dt)`, `load_events($lang, $tz, $cap)`, `build_event_jsonld(...)` (brand),
etc. **Templates need no other changes.**

- `bin/sync.php` is a 3-liner: `require vendor/autoload.php; exit(\Undr\Core\Sync\Cli::run(require config/undr.php));`
- UNLEASHED keeps `public/lib/i18n.php` + `events.php` as **thin shims** that just boot Core,
  so its ~13 entry points need no edits. HEAT/CAGE deleted those libs and edited each entry.

---

## 5. Conventions & rules (read before editing)

- **`load_events($lang, $tz, $cap)`** — `$cap` defaults to `null` (all events). HEAT/CAGE pass
  `5`; UNLEASHED passes nothing (whole season). Match the existing call sites.
- **Never read `$GLOBALS['<BRAND>_CATALOG']` directly.** Use `t()`, `loc_*()`, or
  `\Undr\Core\View\Catalog::raw('key', $default)` / `Catalog::table('name', [])`. (Direct global
  reads silently broke the German pages until they were rewired — don't reintroduce them.)
- **Don't redefine** `h()`/`t()`/`load_events()`/… locally. The Core shims are
  `function_exists`-guarded, so a local definition wins *silently* — only do it on purpose.
- **CSS link order is load-bearing:** `undr-base.css` **before** `<brand>.css`.
- **Use `--undr-*` tokens** for any new shared structure; keep brand specifics (gradients,
  brand colors, animations) in `<brand>.css`. The brand sheet has a `:root { --undr-*: … }`
  block aliasing its palette onto the contract — keep it in sync when you add a token.
- **CSP:** HEAT/CAGE gate inline `<script>` with a per-request nonce (`<?= h($cspNonce) ?>`);
  UNLEASHED has no strict CSP. **All CSS/JS must be first-party** (`/assets/…`) — never load a
  shared asset cross-origin from undr.zone (it would be CSP-blocked).
- **Cache-busting:** asset URLs carry `?v=<filemtime>` against the **real on-disk** file.
- **Never hand-edit `.cache/undr/`** or `public/media/` — they are sync outputs.

---

## 6. The modal JS config (`window.UNDR_MODAL`)

`undr-modal.js` and `undr-tickets.js` are one shared implementation, configured per brand
by a `window.UNDR_MODAL` object set **before** the scripts load. Defaults reproduce HEAT.

```js
window.UNDR_MODAL = {
  source:        'template',   // 'template' (#event-tpl-ID) | 'data-src' ([data-event-src="ID"] .event-detail)
  inertMode:     'siblings',   // 'siblings' (inert body children except .modal) | 'selectors'
  inertSelectors: ['#main'],   // used when inertMode==='selectors'
  inertAriaHidden: false,      // also set aria-hidden on inert targets
  scrollLock:    false,        // position:fixed body scroll-lock + restore on close
  ariaExpanded:  false,        // toggle aria-expanded on the trigger button
  historyClose:  'push',       // 'push' (empty state) | 'back' (history.back the open() entry)
  promo:         true,         // enable the flyer promo-video player
  onClose:       function (dialog) {}  // brand close effect (e.g. HEAT's heatBurst)
};
```

Current brand configs:
- **HEAT** — `{ onClose: d => window.heatBurst && window.heatBurst(d,1.4,0.45) }` (everything else default).
- **CAGE** — `{ source:'data-src', inertMode:'selectors', inertSelectors:['#main','.topnav','.hud','.site-footer','.skip-link'], scrollLock:true, ariaExpanded:true, historyClose:'back', promo:false }`.
- **UNLEASHED** — `{ source:'template', inertMode:'selectors', inertSelectors:['.site-head','#main','.site-foot'], inertAriaHidden:true }`.

**HTML markup contract** the modal binds to:
- Info modal: `#info-modal`, `#info-body`, `[data-modal-backdrop]`, `[data-modal-close]`,
  triggers `[data-open-info="<id>"]`, content source `<template id="event-tpl-<id>">`
  **or** `[data-event-src="<id>"] .event-detail`, heading `.event-detail__name`.
- Promo (optional): `.event-detail__hero-flyer` + `[data-play-promo="<src>"]`.
- Tickets modal (optional): `#tickets-modal`, `#tickets-widget-slot`, `#tickets-alts`
  (+ `.tickets-alts__list`), triggers `[data-open-tickets]` with `data-tickets-loader`,
  `data-tickets-alts` (JSON), `data-event-name|date|venue`.

A brand with no tickets modal (no `#tickets-modal`) simply doesn't load `undr-tickets.js` — it's a no-op.

---

## 7. Editing content (events / venues / translations)

**Event content is NOT in this repo.** It lives in the UNDR backend. To add or change an
event, use the UNDR admin portal or the **UNDR MCP tools** (`upsert_event`, `set_event_flyer`,
`set_event_translation`, `translate_fields`, `publish_event`, `upsert_venue`, …). This site
picks up published changes on the next `bin/sync.php` run. Locally, run `php bin/sync.php` to pull.

---

## 8. Updating `undr/core`

```bash
composer update undr/core      # re-fetch + re-run the publish step
```

In **dev** the package is the local `../UNDR Core` path repo (instant — your edits there are
live after `composer update`, or immediately via the symlink for PHP; re-run publish for assets:
`php vendor/undr/core/scaffold/publish.php`). In **prod** it's the GitHub VCS repo pinned to a
SemVer tag (`"undr/core": "^1.0"`); bump the tag in `UNDR Core`, then `composer update` here.

---

## 9. Running & verifying a change

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

**Parity check (when refactoring, not changing output):** capture the page before and after,
normalize the CSP nonce + `?v=` query strings, and diff. JSON-LD must be byte-identical.

**Behavioral check:** open an event modal, confirm it opens/closes, deep-links (`/#event=<id>`),
and the browser console is **error-free** (a CSP violation or JS error shows up here immediately).

---

## 10. Deploy

Deploy is `git pull` on the server. A git **`post-merge` hook** runs after each pull:

```sh
#!/bin/sh
# .git/hooks/post-merge
composer install --no-dev && php "vendor/undr/core/scaffold/publish.php"
```

Requirements: Composer on the server; for a **private** `undr/core` repo, a Composer auth
token / deploy key so it can clone. A plain `git pull` alone does **not** deploy — `vendor/`
and the published `undr-*` assets are gitignored and rebuilt by the hook.

---

## 11. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| German page renders in English | A template reads `$GLOBALS['<BRAND>_CATALOG']` directly → use `Catalog::raw()` / `t()`. |
| No styling after deploy | The publish step didn't run → `composer install` (or the post-merge hook is missing). |
| `composer` can't resolve `undr/core` | A `path` repo reached prod → switch `composer.json` to the GitHub **VCS** repo + tag. |
| Modal won't open | Check `window.UNDR_MODAL` is set before the scripts, and the markup ids/source match §6. |
| `Fatal: Cannot redeclare h()` | A local lib still defines a Core helper AND Core loaded it → remove the local copy (or guard it). |
| Sync logs `degraded=1` | API unreachable — expected to keep serving cache (exit 0). Investigate if persistent. |

---

## 12. For AI agents working in this repo

- Shared logic lives in **`../UNDR Core`** (or `vendor/undr/core`). Change it **there**, never in `vendor/`.
- **Preserve brand parity.** Don't merge `build_event_jsonld`/ticket-link/.ics across brands — they differ on purpose.
- After any change, **run `php -S` and verify** (golden HTML/JSON-LD diff + a Playwright console check) before claiming done.
- Don't commit `vendor/`, `.cache/`, `public/media/`, or the published `undr-*` assets — all gitignored.
- The `path` Composer repo is dev-only; never let it reach a production commit destined for deploy.
