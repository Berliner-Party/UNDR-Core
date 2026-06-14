# Creating a new UNDR event site

This is the end-to-end recipe for standing up a **new brand site** (e.g. a 4th event
brand) that consumes `undr/core`. Follow it top to bottom; a new brand is ~1 day of
theming + copy with **zero** sync/modal/i18n/JSON-LD plumbing to write.

Read `SITE-DEVELOPMENT.md` first for the architecture and conventions — this doc assumes them.

Conventions below use `<brand>` (slug, e.g. `voltage`) and `<brand.tld>` (domain).

---

## 0. Prerequisites

- PHP 8.1+ and Composer locally.
- The `UNDR Core` checkout available (sibling folder for dev, or the GitHub VCS repo for prod).
- Access to the UNDR backend (admin portal or MCP tools) to register the brand.

---

## 1. Register the brand on the UNDR backend FIRST

The site renders nothing until the API serves the brand. Using the UNDR MCP tools (or admin portal):

1. `upsert_brand` — create the brand (slug `<brand>`, name, timezone `Europe/Berlin`, languages `["en","de"]`, organization meta).
2. `upsert_venue` (if new venues) and `upsert_event` for at least one event; `set_event_flyer`; `publish_event`.
3. Confirm the API responds:
   ```
   GET https://undr.zone/api/v1/brands/<brand>/manifest
   GET https://undr.zone/api/v1/brands/<brand>/events?lang=en&when=all
   ```

---

## 2. Repo skeleton

```
<brand>/
├── composer.json
├── .gitignore
├── bin/sync.php
├── config/undr.php
└── public/
    ├── index.php                 # router
    ├── start.php                 # homepage view
    ├── impressum.php             # (or imprint/legal) — optional
    ├── sitemap.php  robots.php    # optional
    ├── lib/
    │   └── events.brand.php       # brand-divergent helpers (autoloaded)
    ├── lang/
    │   ├── en.php  de.php
    └── assets/
        ├── <brand>.css            # brand sheet (+ :root --undr-* mapping)
        └── <brand>-*.js           # optional brand-unique JS
```

(`vendor/`, `.cache/`, `public/media/`, and the published `undr-*` assets are created by tooling — see `.gitignore` below.)

---

## 3. `composer.json`

```json
{
    "name": "berliner-party/<brand>",
    "description": "<BRAND> (<brand.tld>) — UNDR event site.",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "undr/core": "dev-main"
    },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Berliner-Party/UNDR-Core.git", "no-api": true }
    ],
    "autoload": {
        "files": ["public/lib/events.brand.php"]
    },
    "scripts": {
        "post-install-cmd": ["@php vendor/undr/core/scaffold/publish.php"],
        "post-update-cmd":  ["@php vendor/undr/core/scaffold/publish.php"]
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "optimize-autoloader": true,
        "github-protocols": ["https"],
        "preferred-install": { "undr/core": "source" }
    }
}
```

**All sites pull `undr/core` from the GitHub VCS repo — never a local path.**
- `"undr/core": "dev-main"` tracks the Core repo's `main` branch. For a pinned release instead,
  push a SemVer tag in UNDR-Core and use `"^1.0"`.
- `"no-api": true` + `"preferred-install": {"undr/core":"source"}` make Composer **git-clone**
  the repo into `vendor/undr/core` as a real working checkout (no GitHub zip API). That avoids
  API rate limits/token-for-API and lets you edit/commit/push Core straight from `vendor/undr/core`.
- A **private** UNDR-Core repo needs Composer GitHub auth on every machine/server that installs
  (`composer config --global github-oauth.github.com <token>`, or an SSH deploy key).
- `composer install`/`update` only work once UNDR-Core exists on GitHub and is reachable.

---

## 4. `config/undr.php`

```php
<?php
declare(strict_types=1);
return [
    'apiBase'      => 'https://undr.zone/api/v1',
    'brand'        => '<brand>',          // <-- the only required change vs other sites
    'languages'    => ['en', 'de'],
    'timezone'     => 'Europe/Berlin',
    'cacheDir'     => __DIR__ . '/../.cache/undr',
    'assets'       => 'mirror',
    'mediaDir'     => __DIR__ . '/../public/media',
    'mediaBaseUrl' => '/media',
    'httpTimeout'  => 8,
    'retries'      => 2,
    'maxAgeWarn'   => 21600,
    'apiKey'       => getenv('UNDR_API_KEY') ?: null,
];
```

---

## 5. `bin/sync.php` (cron entry — 3 lines)

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
exit(\Undr\Core\Sync\Cli::run(require dirname(__DIR__) . '/config/undr.php'));
```

Cron: `* * * * * cd /path/to/<brand> && php bin/sync.php >> var/sync.log 2>&1`

---

## 6. `public/index.php` (router skeleton)

Adapt from HEAT's (simple) or UNLEASHED's (extended: blog/redirects/API). Minimal version:

```php
<?php
declare(strict_types=1);

$uri = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

// Static passthrough for the built-in dev server.
if (PHP_SAPI === 'cli-server') {
    $p = realpath(__DIR__ . $uri);
    if ($p !== false && str_starts_with($p, __DIR__) && is_file($p)) return false;
}

// Security headers + CSP (nonce-gated inline scripts). Keep ticket-vendor origins.
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), browsing-topics=()');
$cspNonce = base64_encode(random_bytes(16));
$csp = [
    "default-src 'self'", "base-uri 'self'", "object-src 'none'", "frame-ancestors 'self'",
    "script-src 'self' 'nonce-$cspNonce' 'unsafe-eval' https://rausgegangen.de https://*.rausgegangen.de",
    "style-src 'self' 'unsafe-inline' https://*.rausgegangen.de",
    "font-src 'self' https://*.rausgegangen.de", "img-src 'self' data: https:",
    "media-src 'self' https:", "connect-src 'self' https:", "frame-src 'self' https:",
    "form-action 'self' https:", "worker-src 'self' blob:",
];
header('Content-Security-Policy: ' . implode('; ', $csp));

// Language prefix: EN at /, DE at /de.
$lang = 'en';
if ($uri === '/de' || str_starts_with($uri, '/de/')) { $lang = 'de'; $uri = '/' . trim(substr($uri, 3), '/'); }

$routes = [
    '/'            => 'start.php',
    '/impressum'   => 'impressum.php',
    '/sitemap.xml' => 'sitemap.php',
    '/robots.txt'  => 'robots.php',
];
if (isset($routes[$uri]) && is_file(__DIR__ . '/' . $routes[$uri])) {
    require __DIR__ . '/' . $routes[$uri];
    exit;
}
http_response_code(404);
$is404 = true;
require __DIR__ . '/start.php';   // soft 404 renders the homepage
```

CSP note: if your brand has no inline scripts you can drop the nonce; if you don't use the
rausgegangen widget, drop those origins. **Never** add undr.zone to `script-src`/`style-src` —
shared assets are first-party.

---

## 7. `public/start.php` (homepage view — the wiring that matters)

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';   // Core helpers + events.brand.php
\Undr\Core\Site::configure(['publicDir' => __DIR__, 'brand' => '<brand>']);

$lang     = $lang ?? 'en';
i18n_boot($lang);                                    // loads public/lang/<lang>.php
$cspNonce = $cspNonce ?? '';

$tz       = new DateTimeZone('Europe/Berlin');
$events   = load_events($lang, $tz, 5);              // cap 5, or omit the 5 for "all"
$current  = $events[0] ?? null;

$site     = ['undr' => 'https://undr.zone', /* … brand socials … */];
$baseUrl  = (/* derive scheme+host, guard Host header */ 'https://<brand.tld>');

// Build JSON-LD via the brand helper (in events.brand.php).
$graph = [];
foreach ($events as $e) $graph[] = build_event_jsonld($e, $tz, $baseUrl, $site);
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= h(/* … */ '') ?></title>

  <!-- CSS: shared base BEFORE the brand sheet -->
  <link rel="stylesheet" href="/assets/undr-base.css?v=<?= @filemtime(__DIR__.'/assets/undr-base.css') ?: time() ?>">
  <link rel="stylesheet" href="/assets/<brand>.css?v=<?= @filemtime(__DIR__.'/assets/<brand>.css') ?: time() ?>">

  <script type="application/ld+json" nonce="<?= h($cspNonce) ?>"><?=
    json_encode(['@context'=>'https://schema.org','@graph'=>$graph], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
  <!-- hero, upcoming list, and the modal markup (see SITE-DEVELOPMENT.md §6 for the contract):
       #info-modal/#info-body, [data-open-info], <template id="event-tpl-…"> or [data-event-src],
       optionally #tickets-modal/#tickets-widget-slot/#tickets-alts -->

  <!-- Modal config BEFORE the shared scripts. Pick options per your markup/aesthetic. -->
  <script nonce="<?= h($cspNonce) ?>">
    window.UNDR_MODAL = { source:'template', inertMode:'siblings', promo:true };
  </script>
  <script src="/assets/undr-modal.js?v=<?= @filemtime(__DIR__.'/assets/undr-modal.js') ?: time() ?>" defer></script>
  <script src="/assets/undr-tickets.js?v=<?= @filemtime(__DIR__.'/assets/undr-tickets.js') ?: time() ?>" defer></script>
  <!-- + any brand-unique JS (background effects, etc.) -->
</body>
</html>
```

Copy a sibling brand's `start.php` for the full hero/upcoming/modal markup and adapt the copy.

---

## 8. `public/lib/events.brand.php` (brand-divergent helpers)

Autoloaded by Composer (`autoload.files`). Hold ONLY what differs from other brands; everything
generic comes from Core. Start by copying the closest sibling's `events.brand.php` and adapt:

```php
<?php
declare(strict_types=1);

// Brand JSON-LD shape (org/offers/eventStatus/image fallback to taste).
if (!function_exists('build_event_jsonld')) {
    function build_event_jsonld(array $e, DateTimeZone $tz, string $baseUrl, array $site): array { /* … */ }
}
// Primary ticket link (e.g. derive a rausgegangen widget loader if you use it).
if (!function_exists('primary_ticket_link')) {
    function primary_ticket_link(array $e): ?array { /* … */ }
}
// alt_ticket_links / .ics builder / any brand-only derivations as needed.
```

These call Core helpers freely (`event_dt()`, `lineup_set_artists()`, `asset_renderable()`,
`asset_abs_url()`, `t()`, `lowest_price()`).

---

## 9. `public/assets/<brand>.css` (brand sheet)

Two parts:

**(a) `:root` token-mapping block** — alias the canonical contract onto your palette so shared
structure (and future migrated components) resolve to your brand. The full contract:

```css
:root {
  /* surfaces/ink */ --undr-bg --undr-bg-tint --undr-surface-1 --undr-surface-2
                     --undr-ink --undr-ink-mid --undr-ink-faint --undr-rule --undr-rule-2
  /* accent */       --undr-accent --undr-accent-hot --undr-accent-soft --undr-live
  /* fonts */        --undr-font-display --undr-font-sans --undr-font-mono
  /* scale */        --undr-fs-xs --undr-fs-sm --undr-fs-md --undr-fs-lg --undr-fs-xl --undr-fs-2xl --undr-fs-hero
  /* rhythm */       --undr-gutter --undr-max-w --undr-prose-w --undr-radius --undr-section-y
  /* motion/focus */ --undr-ease --undr-t-fast --undr-t-base --undr-focus
}
```
Set each to your brand value, e.g. `--undr-accent: #ff6b2e;` (or alias an existing brand token:
`--undr-accent: var(--brandAccent);`). Unset tokens fall back to the neutral defaults in
`undr-base.css`.

**(b) brand components** — your fonts (`@font-face`), reset, a11y, hero, modal styling, etc.
`undr-base.css` only ships the token contract + `.sr-only`; everything visible is yours.

> The three sheets today share no byte-identical structural rules, so the brand sheet owns the
> look. When you write a NEW shared-looking component, author it against `--undr-*` so it can
> later move into `undr-base.css`.

---

## 10. `public/lang/en.php` + `de.php` (catalog)

```php
<?php
return [
    'ui' => [ 'more_info' => 'More info', /* … all UI strings … */ ],
    'daysShort'   => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
    'monthsShort' => ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
    'ogLocale'    => 'en_US',
    'countdown'   => ['prefix'=>'T-','d'=>'D','h'=>'H','m'=>'M','s'=>'S','live'=>'NOW · LIVE'],
];
```
Read these via `t('key')`, `loc_*()`, and `\Undr\Core\View\Catalog::raw('ogLocale'|'countdown', $default)`.
**Never** read `$GLOBALS['*_CATALOG']` directly.

---

## 11. `.gitignore`

```gitignore
.DS_Store
vendor/
.cache/
/public/media/
.env
.env.*
# UNDR Core shared assets — published by composer install (regenerated on deploy)
/public/assets/undr-base.css
/public/assets/undr-modal.js
/public/assets/undr-tickets.js
```

---

## 12. Build, run, verify

```bash
composer install                 # installs undr/core + publishes shared assets
php bin/sync.php                  # pull events
php -S 127.0.0.1:8000 -t public public/index.php
```

Open `/` and `/de`. Confirm: events render, the modal opens/closes/deep-links, the browser
console is error-free, and JSON-LD is present (`<script type="application/ld+json">`).

---

## 13. Deploy

- Repo on GitHub (`Berliner-Party/<brand>`); deploy = `git pull` on the server.
- Install the `post-merge` hook (`.git/hooks/post-merge`): `composer install --no-dev && php "vendor/undr/core/scaffold/publish.php"`.
- Switch `composer.json` to the VCS repo + tag (§3) before the first prod deploy; ensure the
  server has Composer + a token for the private `undr/core` repo.
- Set the cron for `bin/sync.php`.

---

## New-brand checklist

- [ ] Brand registered on UNDR backend; `/brands/<brand>/manifest` returns 200
- [ ] Repo skeleton created (§2)
- [ ] `composer.json` (path repo for dev) + `composer install` succeeds + assets published
- [ ] `config/undr.php` has the right `brand` slug
- [ ] `bin/sync.php` 3-liner; `php bin/sync.php` exits 0
- [ ] `public/index.php` routes + CSP correct
- [ ] `public/start.php` boots Core (`Site::configure` + `i18n_boot`), links `undr-base.css` then `<brand>.css`, emits `window.UNDR_MODAL` + the shared scripts
- [ ] `public/lib/events.brand.php` has `build_event_jsonld` (+ ticket helpers) for the brand
- [ ] `<brand>.css` has the `:root --undr-*` mapping + brand components
- [ ] `lang/en.php` + `de.php` filled (ui, daysShort, monthsShort, ogLocale, countdown)
- [ ] `.gitignore` excludes vendor/cache/media/published-assets
- [ ] Local verify: `/` + `/de` render, modal works, console clean, JSON-LD present
- [ ] GitHub repo + remote; `post-merge` deploy hook; cron set; VCS-pinned `undr/core`
