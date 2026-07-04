<?php
declare(strict_types=1);

namespace Undr\Core\View;

use Undr\Core\Site;

// ---------------------------------------------------------------------------
// Blog shortcuts — expand [event:<id>] tokens in a post's sanitized bodyHtml
// into event cards at render time. Authors write, on its own line in the post
// Markdown:
//
//   [event:cage-2026-07-10]        (any brand's event id: <brand>-<YYYY-MM-DD>)
//   [event:2026-07-10]             (date only → the current site's brand)
//
// The token survives the backend's Markdown render as literal text inside a
// <p>, so expansion happens here, against the local sync cache — no request-
// time HTTP, cache-first like everything else:
//
//   own-brand events   ← .cache/undr/events.<lang>.json      (existing sync)
//   other brands       ← .cache/undr/linked-events.<lang>.json (written by the
//                        sync when posts reference foreign events)
//   brand registry     ← .cache/undr/brands.json              (slug → website,
//                        name, languages; from GET /api/v1/brands)
//
// A token whose event can't be resolved renders as NOTHING (the codebase's
// graceful-degradation rule: never leak raw [event:…] to readers).
//
// The card: flyer, brand kicker, name, localized date/doors/venue line, short
// description, a Buy-Tickets button and a More-Info button.
//
//   More info  → <a href="<site><langPrefix>/#event=<id>">. For the current
//     brand the href is site-relative and the anchor also carries
//     data-open-info, so on pages that embed the event templates the shared
//     modal opens in place; everywhere else undr-modal.js lets the link
//     navigate and the #event= hash deep-link opens the modal on arrival —
//     including on ANOTHER brand's site, in the current page's language.
//   Buy tickets → the brand's primary ticket link. If the site's
//     primary_ticket_link() returns a widget 'loader' URL (rausgegangen),
//     the button carries the [data-open-tickets] contract and opens the shared
//     tickets modal; otherwise (and for foreign/cross-brand events) it links
//     the ticket page directly in a new tab.
//
// Modes: card (post page), feed (card with absolute URLs for RSS) and text
// (plain inline sentence, for articleBody/wordCount projections).
// ---------------------------------------------------------------------------
final class BlogShortcuts
{
    private const TOKEN = '\[event:\s*([a-z0-9][a-z0-9-]{0,80})\s*\]';

    /** @var array<string,mixed>|null per-request caches, keyed by lang */
    private static array $events = [];
    private static array $linked = [];
    private static ?array $brands = null;

    // -----------------------------------------------------------------------
    /**
     * Replace every [event:…] token in $html with an event card. A token that
     * fills a whole paragraph becomes a block card; one inline in running text
     * becomes a plain link (a block card inside <p> would be invalid HTML).
     * $baseUrl absolutizes own-site URLs (pass the brand base for RSS).
     */
    public static function expand(string $html, ?string $lang = null, string $baseUrl = ''): string
    {
        return self::replaceTokens($html, $lang ?? Catalog::lang(), $baseUrl, 'card');
    }

    /** Replace tokens with a plain-text mention (JSON-LD articleBody etc.). */
    public static function strip(string $html, ?string $lang = null): string
    {
        return self::replaceTokens($html, $lang ?? Catalog::lang(), '', 'text');
    }

    private static function replaceTokens(string $html, string $lang, string $baseUrl, string $mode): string
    {
        if ($html === '' || stripos($html, '[event:') === false) return $html;

        // Pass 1: a token that IS the whole paragraph → replace the <p> itself.
        $html = (string) preg_replace_callback(
            '~<p>\s*' . self::TOKEN . '\s*</p>~i',
            fn($m) => self::renderToken($m[1], $lang, $baseUrl, $mode === 'card' ? 'card' : 'text-p'),
            $html
        );
        // Pass 2: inline occurrences → a link (or plain text in text mode).
        return (string) preg_replace_callback(
            '~' . self::TOKEN . '~i',
            fn($m) => self::renderToken($m[1], $lang, $baseUrl, $mode === 'card' ? 'inline' : 'text'),
            $html
        );
    }

    // -----------------------------------------------------------------------
    // Token → HTML
    // -----------------------------------------------------------------------
    private static function renderToken(string $ref, string $lang, string $baseUrl, string $shape): string
    {
        $resolved = self::resolve(strtolower($ref), $lang);
        if ($resolved === null) return ''; // unresolvable → render nothing

        [$e, $own] = $resolved;

        $name  = (string) ($e['name'] ?? '');
        $meta  = self::metaLine($e);
        $venue = self::venueLine($e);
        $info  = self::moreInfoUrl($e, $own, $lang, $baseUrl);

        if ($shape === 'text' || $shape === 'text-p') {
            $text = $name . ' — ' . $meta . ($venue !== '' ? ', ' . $venue : '') . '.';
            return $shape === 'text-p' ? '<p>' . h($text) . '</p>' : h($text);
        }

        if ($shape === 'inline') {
            $label = $name . ' — ' . $meta;
            return $info !== ''
                ? '<a class="undr-event-link" href="' . h($info) . '">' . h($label) . '</a>'
                : h($label);
        }

        return self::renderCard($e, $own, $lang, $baseUrl, $name, $meta, $venue, $info);
    }

    private static function renderCard(
        array $e, bool $own, string $lang, string $baseUrl,
        string $name, string $meta, string $venue, string $info
    ): string {
        $id    = (string) ($e['id'] ?? '');
        $past  = self::isPast($e);
        $brand = self::brandDisplayName($e, $own);

        $out = '<aside class="undr-event-card' . ($past ? ' undr-event-card--past' : '')
             . '" data-undr-event="' . h($id) . '" aria-label="' . h($brand . ': ' . $name) . '">';

        // Flyer (own-brand flyers are mirrored locally; foreign ones hotlink the
        // absolute UNDR media URL injected by the sync).
        $flyer = is_array($e['flyer'] ?? null) ? $e['flyer'] : [];
        $src   = (string) ($flyer['src'] ?? '');
        if ($src !== '' && EventRepository::assetRenderable($src)) {
            if ($baseUrl !== '') $src = EventRepository::assetAbsUrl($src, rtrim($baseUrl, '/'));
            $img = '<img src="' . h($src) . '" alt="' . h((string) ($flyer['alt'] ?? '')) . '" loading="lazy" decoding="async">';
            $webp = (string) ($flyer['webp'] ?? '');
            if ($webp !== '') {
                if ($baseUrl !== '') $webp = EventRepository::assetAbsUrl($webp, rtrim($baseUrl, '/'));
                $img = '<picture><source type="image/webp" srcset="' . h($webp) . '">' . $img . '</picture>';
            }
            $out .= $info !== ''
                ? '<a class="undr-event-card__media" href="' . h($info) . '"'
                    . ($own && !$past ? ' data-open-info="' . h($id) . '"' : '')
                    . ' tabindex="-1" aria-hidden="true">' . $img . '</a>'
                : '<div class="undr-event-card__media">' . $img . '</div>';
        }

        $out .= '<div class="undr-event-card__body">';
        $out .= '<p class="undr-event-card__kicker">' . h($brand) . '</p>';
        $out .= '<h3 class="undr-event-card__name">' . h($name) . '</h3>';
        $out .= '<p class="undr-event-card__meta">' . h($meta . ($venue !== '' ? ' · ' . $venue : '')) . '</p>';

        $desc = (string) ($e['shortDescription'] ?? '');
        if ($desc !== '') {
            $out .= '<p class="undr-event-card__desc">' . h(seo_clip($desc, 140)) . '</p>';
        }

        if ($past) {
            $out .= '<p class="undr-event-card__past-note">' . h(self::label('blog_event_past', $lang)) . '</p>';
        } else {
            $out .= '<div class="undr-event-card__actions">';
            $out .= self::ticketsButton($e, $own, $lang);
            if ($info !== '') {
                $out .= '<a class="undr-event-card__btn" href="' . h($info) . '"'
                      . ($own ? ' data-open-info="' . h($id) . '"' : '')
                      . '>' . h(self::label('blog_event_more_info', $lang)) . '</a>';
            }
            $out .= '</div>';
        }

        return $out . '</div></aside>';
    }

    /** The Buy-Tickets anchor, or '' when the event has no ticket link. */
    private static function ticketsButton(array $e, bool $own, string $lang): string
    {
        $primary = null;
        if ($own && function_exists('primary_ticket_link')) {
            $p = primary_ticket_link($e);
            if (is_array($p) && !empty($p['url'])) $primary = $p;
        }
        if ($primary === null) {
            foreach ($e['ticketLinks'] ?? [] as $l) {
                if (empty($l['url'])) continue;
                if ($primary === null || !empty($l['primary'])) $primary = $l;
                if (!empty($l['primary'])) break;
            }
        }
        if ($primary === null) return '';

        $attrs = 'class="undr-event-card__btn undr-event-card__btn--primary" href="' . h((string) $primary['url'])
               . '" target="_blank" rel="noopener"';

        // Widget path (own brand only): primary_ticket_link() exposing a
        // 'loader' (rausgegangen external-loader.js URL) → shared tickets modal,
        // with the plain href as the no-JS / no-modal fallback.
        $loader = $own ? (string) ($primary['loader'] ?? $primary['widget'] ?? '') : '';
        if ($loader !== '') {
            $dt = EventDerive::eventDt($e, 'doorsOpen', self::tz($e));
            $attrs .= ' data-open-tickets data-tickets-loader="' . h($loader) . '"'
                    . ' data-event-name="' . h((string) ($e['name'] ?? '')) . '"'
                    . ' data-event-date="' . h(loc_tix_datetime($dt)) . '"'
                    . ' data-event-venue="' . h(self::venueLine($e)) . '"';
            if (function_exists('alt_ticket_links')) {
                $alts = alt_ticket_links($e);
                if ($alts !== []) $attrs .= ' data-tickets-alts="' . attr($alts) . '"';
            }
        }

        return '<a ' . $attrs . '>' . h(self::label('blog_event_tickets', $lang)) . '</a>';
    }

    // -----------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------
    /** @return array{0: array, 1: bool}|null [event, isOwnBrand] or null */
    private static function resolve(string $ref, string $lang): ?array
    {
        $own = Site::brand();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
            $id = $own . '-' . $ref;                       // date-only → own brand
            $brand = $own;
        } elseif (preg_match('/^(.+)-(\d{4}-\d{2}-\d{2})$/', $ref, $m)) {
            $id = $ref;
            $brand = $m[1];
        } else {
            return null;
        }

        if ($brand === $own) {
            $e = self::ownEvents($lang)[$id] ?? null;
            return $e === null ? null : [$e, true];
        }
        $e = self::linkedEvents($lang)[$id] ?? null;
        return $e === null ? null : [$e, false];
    }

    /** @return array<string,array> own-brand events by id (raw snapshot, past included) */
    private static function ownEvents(string $lang): array
    {
        if (!isset(self::$events[$lang])) {
            self::$events[$lang] = self::indexById(self::readSnapshot('events', $lang));
        }
        return self::$events[$lang];
    }

    /** @return array<string,array> linked (foreign-brand) events by id */
    private static function linkedEvents(string $lang): array
    {
        if (!isset(self::$linked[$lang])) {
            $raw = self::readSnapshot('linked-events', $lang);
            // Written as an id-keyed map; tolerate a plain list too.
            self::$linked[$lang] = array_is_list($raw) ? self::indexById($raw) : $raw;
        }
        return self::$linked[$lang];
    }

    private static function readSnapshot(string $prefix, string $lang): array
    {
        $dir = EventRepository::cacheDir();
        if ($dir === null) return [];
        $data = EventRepository::readJson($dir . '/' . $prefix . '.' . $lang . '.json');
        if ($data === null && $lang !== 'en') {
            $data = EventRepository::readJson($dir . '/' . $prefix . '.en.json');
        }
        return is_array($data) ? $data : [];
    }

    private static function indexById(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            if (is_array($e) && !empty($e['id'])) $out[(string) $e['id']] = $e;
        }
        return $out;
    }

    /** @return array<string,array> brand registry by slug (synced brands.json) */
    private static function brands(): array
    {
        if (self::$brands === null) {
            self::$brands = [];
            $dir = EventRepository::cacheDir();
            $list = $dir !== null ? EventRepository::readJson($dir . '/brands.json') : null;
            foreach (is_array($list) ? $list : [] as $b) {
                if (is_array($b) && !empty($b['slug'])) self::$brands[(string) $b['slug']] = $b;
            }
        }
        return self::$brands;
    }

    // -----------------------------------------------------------------------
    // Derived bits
    // -----------------------------------------------------------------------
    /**
     * More-info deep link: <site><langPrefix>/#event=<id>. Own brand →
     * site-relative ('' base) unless $baseUrl absolutizes it (feed). Foreign →
     * the event's own site (synced _website), in the current language when that
     * site speaks it. '' when a foreign event carries no website.
     */
    private static function moreInfoUrl(array $e, bool $own, string $lang, string $baseUrl): string
    {
        $id = (string) ($e['id'] ?? '');
        if ($own) {
            $base  = $baseUrl !== '' ? rtrim($baseUrl, '/') : '';
            $langs = null; // own site obviously serves the current language
        } else {
            $base  = rtrim((string) ($e['_website'] ?? ''), '/');
            if ($base === '') return '';
            $langs = $e['_languages'] ?? (self::brands()[(string) ($e['_brand'] ?? '')]['languages'] ?? null);
        }
        $prefix = '';
        if ($lang !== 'en' && (!is_array($langs) || in_array($lang, $langs, true))) {
            $prefix = '/' . $lang;
        }
        return $base . $prefix . '/#event=' . rawurlencode($id);
    }

    /** "Fri, 10 Jul 2026 · 23:00" — localized via the site's catalog tables. */
    private static function metaLine(array $e): string
    {
        try {
            return loc_tix_datetime(EventDerive::eventDt($e, 'doorsOpen', self::tz($e)));
        } catch (\Throwable) {
            return (string) ($e['date'] ?? '');
        }
    }

    private static function venueLine(array $e): string
    {
        $v = is_array($e['venue'] ?? null) ? $e['venue'] : [];
        $name = (string) ($v['name'] ?? '');
        $city = (string) ($v['addressLocality'] ?? '');
        if ($name === '') return $city;
        return $city !== '' ? $name . ', ' . $city : $name;
    }

    private static function brandDisplayName(array $e, bool $own): string
    {
        if (!$own && !empty($e['_brandName'])) return (string) $e['_brandName'];
        $slug = $own ? Site::brand() : (string) ($e['_brand'] ?? '');
        $reg  = self::brands()[$slug]['name'] ?? null;
        return is_string($reg) && $reg !== '' ? $reg : mb_strtoupper($slug, 'UTF-8');
    }

    private static function isPast(array $e): bool
    {
        $date = (string) ($e['date'] ?? '');
        if ($date === '') return false;
        return $date < (new \DateTime('today', self::tz($e)))->format('Y-m-d');
    }

    private static function tz(array $e): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) ($e['timezone'] ?? 'Europe/Berlin'));
        } catch (\Throwable) {
            return new \DateTimeZone('Europe/Berlin');
        }
    }

    /**
     * Card label: the site catalog's key wins when defined (t() falls back to
     * the key itself when missing), else a built-in en/de default — so the
     * cards work with zero per-site config but stay brand-overridable.
     */
    private static function label(string $key, string $lang): string
    {
        $t = t($key);
        if ($t !== $key) return $t;
        $defaults = [
            'blog_event_tickets'   => ['en' => 'Buy Tickets', 'de' => 'Tickets kaufen'],
            'blog_event_more_info' => ['en' => 'More Info',   'de' => 'Mehr Infos'],
            'blog_event_past'      => ['en' => 'This event has taken place.', 'de' => 'Dieses Event hat bereits stattgefunden.'],
        ];
        return $defaults[$key][$lang] ?? $defaults[$key]['en'] ?? $key;
    }

    /** Test seam: drop the per-request caches (also useful in long-running CLIs). */
    public static function resetCache(): void
    {
        self::$events = [];
        self::$linked = [];
        self::$brands = null;
    }
}
