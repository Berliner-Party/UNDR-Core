# UNDR Core

Shared core for the UNDR family of event sites (HEAT, CAGE, UNLEASHED, and future
brands). One place to maintain the things every site duplicated: the UNDR **sync
engine**, the shared **view helpers**, and the base **CSS/JS**.

Consumed as a Composer package (`undr/core`). PHP is autoloaded; CSS/JS are
published as first-party files into each site's `public/assets/`.

## What's inside

```
src/Sync/      UndrSync, SyncResult, Cli   — pull UNDR API → local cache (byte-identical across all sites)
src/Http/      UndrHttp, UndrResponse      — dependency-free conditional GET client
src/View/      Catalog, EventRepository, EventDerive — i18n + event loading + small derivations
src/Site.php   Site::configure([...])      — per-site paths/brand bootstrap
functions/     html/i18n/dates/events      — global-function shims (h(), t(), load_events(), …),
                                             function_exists-guarded so templates need no rewrite
assets/css/    undr-base.css               — shared structure + canonical --undr-* token contract
assets/js/     undr-modal.js, undr-tickets.js — event + tickets modal cores with onOpen/onClose hooks
scaffold/      publish.php                 — copy assets/ → a site's public/assets/
bin/undr-sync  generic cron entry point
```

### What is intentionally NOT shared

The view helpers that have genuinely diverged per brand stay in each site's
`public/lib/events.brand.php` — forcing one version would change a brand's rendered
output: `build_event_jsonld`, `primary_ticket_link`, `alt_ticket_links`, the `.ics`
builder, and each brand's extras (CAGE timetable/FAQ, UNLEASHED ticket phases, …).

## Using it in a site

`composer.json`:

```json
{
  "require": { "php": ">=8.1", "undr/core": "^1.0" },
  "repositories": [{ "type": "path", "url": "../UNDR Core" }],
  "scripts": {
    "post-install-cmd": ["php vendor/undr/core/scaffold/publish.php"],
    "post-update-cmd":  ["php vendor/undr/core/scaffold/publish.php"]
  }
}
```

(Production switches the `path` repository for the GitHub VCS repo + a SemVer tag.)

Entry script (start.php / sitemap.php), replacing the old `require_once lib/*`:

```php
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/lib/events.brand.php';        // brand-divergent helpers
\Undr\Core\Site::configure(['publicDir' => __DIR__, 'brand' => 'heat']);
i18n_boot($lang);                                 // works unchanged
```

`bin/sync.php` (cron) becomes a 3-liner:

```php
#!/usr/bin/env php
<?php
require dirname(__DIR__) . '/vendor/autoload.php';
exit(\Undr\Core\Sync\Cli::run(require dirname(__DIR__) . '/config/undr.php'));
```

### CSS

Each site links the shared base **before** its brand sheet:

```html
<link rel="stylesheet" href="/assets/undr-base.css?v=<filemtime>">
<link rel="stylesheet" href="/assets/heat.css?v=<filemtime>">
```

`undr-base.css` is structure built against the canonical `--undr-*` token
contract; the brand sheet supplies those token values plus brand-unique
components. See `docs/token-contract.md`.

## Versioning

SemVer. Sites pin `^1.0` and upgrade on their own schedule. Deploy runs
`composer install` + the publish step (a git `post-merge` hook keeps `git pull`
as the deploy command — see `docs/deploy.md`).
