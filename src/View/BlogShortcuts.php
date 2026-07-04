<?php
declare(strict_types=1);

namespace Undr\Core\View;

use Undr\Core\Site;

// ---------------------------------------------------------------------------
// Blog shortcodes — expand bracket tokens in a post's sanitized bodyHtml into
// rich blocks at render time. Authors write them in the post Markdown; the
// backend's escape-first renderer passes them through as literal text (no
// autolinking, brackets untouched), so expansion happens here, against the
// local sync cache — no request-time HTTP, cache-first like everything else.
// Full authoring reference: docs/BLOG-SHORTCODES.md.
//
//   [event:cage-2026-07-10]                       event card (see below)
//   [image: <url> | <alt> | <caption>]            responsive figure (+lightbox)
//   [gallery: <url> :: <alt> | <url> :: <alt>]    thumb grid (+lightbox)
//   [video: <youtube url or id> | <title>]        GDPR click-to-load facade
//   [button: <url> | <label>]                     CTA button
//   [quote: <text> | <attribution>]               styled pull-quote
//   [map: <address or venue> | <label>]           Google-Maps link-out
//   [html] …raw markup… [/html]                   trusted raw-HTML escape hatch
//
// Rules shared by all codes: a token filling a whole paragraph renders its
// block form; inline occurrences render a text/link fallback (a block inside
// <p> would be invalid HTML). A payload can never contain "](" (the backend's
// Markdown link regex would eat it). Payloads are strip_tag'd and entity-
// decoded before parsing, so `&`→`&amp;` in URLs and stray inline tags are
// harmless. An unresolvable/empty token renders NOTHING — never leak raw
// [tokens] to readers. [html] payloads are read from bodyMarkdown (the
// sanitizer escapes them in bodyHtml but the source keeps them verbatim);
// authors are trusted (the portal is first-party).
//
// The EVENT card resolves against the sync cache:
//   own-brand events   ← .cache/undr/events.<lang>.json      (existing sync)
//   other brands       ← .cache/undr/linked-events.<lang>.json (written by the
//                        sync when posts reference foreign events)
//   brand registry     ← .cache/undr/brands.json              (slug → website,
//                        name, languages; from GET /api/v1/brands)
//
//   More info  → <a href="<site><langPrefix>/#event=<id>">. For the current
//     brand the href is site-relative and the anchor also carries
//     data-open-info, so on pages that embed the event templates the shared
//     modal opens in place; everywhere else undr-modal.js lets the link
//     navigate and the #event= hash deep-link opens the modal on arrival —
//     including on ANOTHER brand's site, in the current page's language.
//   Buy tickets → the brand's primary ticket link. If the site's
//     primary_ticket_link() returns a widget 'loader'/'widget' URL
//     (rausgegangen), the button carries the [data-open-tickets] contract and
//     opens the shared tickets modal; otherwise (and for foreign/cross-brand
//     events) it links the ticket page directly in a new tab.
//
// Modes: card (post page), feed (card with absolute URLs for RSS — pass
// $baseUrl) and text (plain projections for JSON-LD articleBody/wordCount).
// ---------------------------------------------------------------------------
final class BlogShortcuts
{
    /** All colon-payload codes handled by the dispatch below. */
    private const CODES = 'event|image|gallery|video|button|quote|map';

    /** @var array<string,mixed>|null per-request caches, keyed by lang */
    private static array $events = [];
    private static array $linked = [];
    private static ?array $brands = null;

    // -----------------------------------------------------------------------
    /**
     * Replace every shortcode in $html with its rendered block. $baseUrl
     * absolutizes own-site URLs (pass the brand base for RSS). $opts:
     *   markdown — the post's bodyMarkdown; required for [html]…[/html]
     *              payloads (without it those blocks render as nothing).
     */
    public static function expand(string $html, ?string $lang = null, string $baseUrl = '', array $opts = []): string
    {
        return self::replaceTokens($html, $lang ?? Catalog::lang(), $baseUrl, 'card', (string) ($opts['markdown'] ?? ''));
    }

    /** Replace shortcodes with plain-text projections (JSON-LD articleBody etc.). */
    public static function strip(string $html, ?string $lang = null, array $opts = []): string
    {
        return self::replaceTokens($html, $lang ?? Catalog::lang(), '', 'text', (string) ($opts['markdown'] ?? ''));
    }

    private static function replaceTokens(string $html, string $lang, string $baseUrl, string $mode, string $markdown): string
    {
        if ($html === '' || strpos($html, '[') === false) return $html;
        if (!preg_match('~\[(?:' . self::CODES . '|html)[:\]]~i', $html)) return $html;

        // Pass 0: [html]…[/html] blocks. The sanitizer escaped their contents in
        // bodyHtml, so the Nth marker pair here maps to the Nth verbatim block in
        // bodyMarkdown. Runs first so raw payloads aren't re-scanned for codes.
        if (stripos($html, '[html]') !== false) {
            preg_match_all('~\[html\](.*?)\[/html\]~is', $markdown, $mm);
            $blocks = $mm[1] ?? [];
            $i = 0;
            $html = (string) preg_replace_callback(
                '~<p>\s*\[html\].*?\[/html\]\s*</p>|\[html\].*?\[/html\]~is',
                function () use (&$i, $blocks, $mode) {
                    $raw = trim((string) ($blocks[$i] ?? ''));
                    $i++;
                    return $mode === 'text' ? '' : $raw;
                },
                $html
            );
        }

        $uid = 0; // per-body counter for lightbox group ids

        // Pass 1: a token that IS the whole paragraph → replace the <p> itself
        // with the code's block form.
        $html = (string) preg_replace_callback(
            '~<p>\s*\[(' . self::CODES . '):\s*([^\]]*)\]\s*</p>~is',
            fn($m) => self::renderCode(strtolower($m[1]), $m[2], true, $mode, $lang, $baseUrl, $uid),
            $html
        );
        // Pass 2: inline occurrences → text/link fallbacks.
        return (string) preg_replace_callback(
            '~\[(' . self::CODES . '):\s*([^\]]*)\]~is',
            fn($m) => self::renderCode(strtolower($m[1]), $m[2], false, $mode, $lang, $baseUrl, $uid),
            $html
        );
    }

    // -----------------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------------
    private static function renderCode(string $code, string $raw, bool $block, string $mode, string $lang, string $baseUrl, int &$uid): string
    {
        // Payload hygiene: drop stray inline tags (<br> across wrapped lines),
        // undo the sanitizer's entity escaping (&amp; in URLs), then split on |.
        $payload = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts   = array_values(array_filter(array_map('trim', explode('|', $payload)), fn($p) => $p !== ''));

        if ($code === 'event') {
            $shape = $mode === 'text' ? ($block ? 'text-p' : 'text') : ($block ? 'card' : 'inline');
            return self::renderEvent($payload, $lang, $baseUrl, $shape);
        }
        if ($parts === []) return '';

        if ($mode === 'text') {
            $txt = self::textOf($code, $parts);
            return $txt === '' ? '' : ($block ? '<p>' . h($txt) . '</p>' : h($txt));
        }

        return match ($code) {
            'image'   => self::renderImage($parts, $block, $baseUrl, $uid),
            'gallery' => self::renderGallery($parts, $block, $baseUrl, $uid),
            'video'   => self::renderVideo($parts, $block, $lang, $baseUrl),
            'button'  => self::renderButton($parts, $block, $baseUrl),
            'quote'   => self::renderQuote($parts, $block),
            'map'     => self::renderMap($parts, $block, $lang),
            default   => '',
        };
    }

    /** Plain-text projection per code (articleBody / wordCount). */
    private static function textOf(string $code, array $parts): string
    {
        return match ($code) {
            'image'  => (string) ($parts[2] ?? $parts[1] ?? ''),        // caption, else alt
            'video'  => self::videoTitle($parts),
            'button' => (string) ($parts[1] ?? ''),
            'quote'  => $parts[0] . (isset($parts[1]) ? ' — ' . $parts[1] : ''),
            'map'    => (string) ($parts[0] ?? ''),
            default  => '',                                             // gallery
        };
    }

    // -----------------------------------------------------------------------
    // Simple codes
    // -----------------------------------------------------------------------
    private static function renderImage(array $parts, bool $block, string $baseUrl, int &$uid): string
    {
        $src = self::absUrl($parts[0], $baseUrl);
        if (!preg_match('~^(?:https?://|/)~i', $src)) return '';
        $alt     = (string) ($parts[1] ?? '');
        $caption = (string) ($parts[2] ?? '');

        if (!$block) { // inline → a plain link (a <figure> inside <p> is invalid)
            $label = $alt !== '' ? $alt : basename(parse_url($src, PHP_URL_PATH) ?: $src);
            return '<a class="undr-event-link" href="' . h($src) . '">' . h($label) . '</a>';
        }

        $uid++;
        $out = '<figure class="undr-figure">'
             . '<a href="' . h($src) . '" data-undr-lightbox="fig-' . $uid . '">'
             . '<img src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy" decoding="async">'
             . '</a>';
        if ($caption !== '') $out .= '<figcaption>' . h($caption) . '</figcaption>';
        return $out . '</figure>';
    }

    private static function renderGallery(array $parts, bool $block, string $baseUrl, int &$uid): string
    {
        if (!$block) return ''; // gallery is block-only (documented)
        $uid++;
        $items = '';
        foreach ($parts as $item) {
            [$src, $alt] = array_pad(array_map('trim', explode('::', $item, 2)), 2, '');
            $src = self::absUrl($src, $baseUrl);
            if (!preg_match('~^(?:https?://|/)~i', $src)) continue;
            $items .= '<a class="undr-gallery__item" href="' . h($src) . '" data-undr-lightbox="gal-' . $uid . '">'
                    . '<img src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy" decoding="async">'
                    . '</a>';
        }
        return $items === '' ? '' : '<div class="undr-gallery">' . $items . '</div>';
    }

    private static function renderVideo(array $parts, bool $block, string $lang, string $baseUrl): string
    {
        // Local file (first-party .mp4/.webm/.mov) → native <video> player with
        // a configurable 16:9 / 9:16 ratio box and an optional reel `loop` flag.
        $path = (string) (parse_url($parts[0], PHP_URL_PATH) ?: $parts[0]);
        if (preg_match('~\.(mp4|webm|mov)$~i', $path)) {
            return self::renderLocalVideo($parts, $block, $lang, $baseUrl);
        }

        $id    = self::youtubeId($parts[0]);
        $title = (string) ($parts[1] ?? '');
        if ($id === '') {
            // Not a recognizable YouTube ref → degrade to a plain link (if it's a
            // URL at all), labelled with the title.
            if (!preg_match('~^https?://~i', $parts[0])) return '';
            $label = $title !== '' ? $title : self::label('blog_video_play', $lang);
            return '<a class="undr-event-link" href="' . h($parts[0]) . '" target="_blank" rel="noopener">' . h($label) . '</a>';
        }

        $watch = 'https://www.youtube.com/watch?v=' . $id;
        $label = $title !== '' ? $title : self::label('blog_video_play', $lang);
        if (!$block) {
            return '<a class="undr-event-link" href="' . h($watch) . '" target="_blank" rel="noopener">' . h($label) . '</a>';
        }

        // Click-to-load facade: zero third-party requests until the visitor acts
        // (GDPR). undr-blog.js swaps it for a youtube-nocookie <iframe>; without
        // JS (and in feed readers) it's a plain link to YouTube.
        return '<div class="undr-video">'
             . '<a class="undr-video__facade" href="' . h($watch) . '" data-undr-video="' . h($id) . '"'
             . ' data-undr-video-title="' . h($label) . '" target="_blank" rel="noopener">'
             . '<span class="undr-video__play" aria-hidden="true"></span>'
             . '<span class="undr-video__title">' . h($label) . '</span>'
             . '</a></div>';
    }

    /**
     * Native player for a self-hosted clip:
     *   [video: /media/x.mp4 | title | 16:9|9:16 | loop]
     * Ratio and `loop` are order-agnostic after the title. Controls always
     * (accessible sound/pause even in reel mode); `loop` adds autoplay muted
     * loop — the reel behavior. object-fit: contain — never crop (matches the
     * brands' flyer components).
     */
    private static function renderLocalVideo(array $parts, bool $block, string $lang, string $baseUrl): string
    {
        $src = self::absUrl($parts[0], $baseUrl);
        if (!preg_match('~^(?:https?://|/)~i', $src)) return '';
        $title    = self::videoTitle($parts);
        $flags    = array_map('strtolower', array_slice($parts, 1));
        $vertical = in_array('9:16', $flags, true);
        $loop     = in_array('loop', $flags, true);

        if (!$block) {
            $label = $title !== '' ? $title : self::label('blog_video_play', $lang);
            return '<a class="undr-event-link" href="' . h($src) . '">' . h($label) . '</a>';
        }

        $vAttrs = 'src="' . h($src) . '" controls preload="metadata" playsinline';
        if ($loop) $vAttrs .= ' autoplay muted loop';
        if ($title !== '') $vAttrs .= ' aria-label="' . h($title) . '"';

        // Vertical reels float beside the running text on wide viewports
        // (magazine wrap — no dead flanks); the figure carries the modifier.
        $out = '<figure class="undr-figure undr-figure--video' . ($vertical ? ' undr-figure--vertical' : '') . '">'
             . '<div class="undr-video undr-video--local' . ($vertical ? ' undr-video--vertical' : '') . '">'
             . '<video ' . $vAttrs . '></video></div>';
        if ($title !== '') $out .= '<figcaption>' . h($title) . '</figcaption>';
        return $out . '</figure>';
    }

    private static function renderButton(array $parts, bool $block, string $baseUrl): string
    {
        $url = self::absUrl($parts[0], $baseUrl);
        if (!preg_match('~^(?:https?://|/|mailto:)~i', $url)) return '';
        $label    = (string) ($parts[1] ?? $parts[0]);
        $external = (bool) preg_match('~^https?://~i', $url);
        $attrs    = 'href="' . h($url) . '"' . ($external ? ' target="_blank" rel="noopener"' : '');

        if (!$block) return '<a class="undr-event-link" ' . $attrs . '>' . h($label) . '</a>';
        return '<p class="undr-cta"><a class="undr-cta__btn" ' . $attrs . '>' . h($label) . '</a></p>';
    }

    private static function renderQuote(array $parts, bool $block): string
    {
        $text = $parts[0];
        $cite = (string) ($parts[1] ?? '');
        if (!$block) return h('“' . $text . '”' . ($cite !== '' ? ' — ' . $cite : ''));
        $out = '<blockquote class="undr-quote"><p>' . h($text) . '</p>';
        if ($cite !== '') $out .= '<cite>' . h($cite) . '</cite>';
        return $out . '</blockquote>';
    }

    private static function renderMap(array $parts, bool $block, string $lang): string
    {
        $query = $parts[0];
        $url   = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
        $label = (string) ($parts[1] ?? '');
        if ($label === '') $label = self::label('blog_map_open', $lang);
        $a = '<a class="' . ($block ? 'undr-map__link' : 'undr-event-link')
           . '" href="' . h($url) . '" target="_blank" rel="noopener">'
           . h($label . ' — ' . $query) . '</a>';
        return $block ? '<p class="undr-map">' . $a . '</p>' : $a;
    }

    /** Root-relative URL → absolute when a feed base is set; else passthrough. */
    private static function absUrl(string $url, string $baseUrl): string
    {
        if ($baseUrl !== '' && $url !== '' && $url[0] === '/' && !str_starts_with($url, '//')) {
            return rtrim($baseUrl, '/') . $url;
        }
        return $url;
    }

    /** The [video] title = first payload part after the URL that isn't a ratio/loop flag. */
    private static function videoTitle(array $parts): string
    {
        foreach (array_slice($parts, 1) as $p) {
            $flag = strtolower($p);
            if ($flag !== '9:16' && $flag !== '16:9' && $flag !== 'loop') return $p;
        }
        return '';
    }

    /** The 11-char YouTube id from a bare id / watch / youtu.be / embed / shorts URL. */
    private static function youtubeId(string $ref): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $ref)) return $ref;
        if (!preg_match('~^https?://(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com|youtu\.be)/~i', $ref)) return '';
        if (preg_match('~youtu\.be/([A-Za-z0-9_-]{11})~i', $ref, $m)) return $m[1];
        if (preg_match('~/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})~i', $ref, $m)) return $m[1];
        if (preg_match('~[?&]v=([A-Za-z0-9_-]{11})~i', $ref, $m)) return $m[1];
        return '';
    }

    // -----------------------------------------------------------------------
    // Event card (unchanged behavior from the original [event:…] shortcut)
    // -----------------------------------------------------------------------
    private static function renderEvent(string $ref, string $lang, string $baseUrl, string $shape): string
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

        return self::renderEventCard($e, $own, $lang, $baseUrl, $name, $meta, $venue, $info);
    }

    private static function renderEventCard(
        array $e, bool $own, string $lang, string $baseUrl,
        string $name, string $meta, string $venue, string $info
    ): string {
        $id    = (string) ($e['id'] ?? '');
        $past  = self::isPast($e);
        $brand = self::brandDisplayName($e, $own);

        $out = '<aside class="undr-event-card' . ($past ? ' undr-event-card--past' : '')
             . '" data-undr-event="' . h($id) . '" aria-label="' . h($brand . ': ' . $name) . '">';

        // Flyer at its native 16:9 (1920×1080 — never cropped). Own-brand flyers
        // are mirrored locally; foreign ones hotlink the UNDR media URL injected
        // by the sync. The flyer artwork already carries name/date/venue, so the
        // card shows NO text next to it — just the flyer and the two buttons
        // (the aside's aria-label + the alt text keep it accessible).
        $flyer = is_array($e['flyer'] ?? null) ? $e['flyer'] : [];
        $src   = (string) ($flyer['src'] ?? '');
        $hasFlyer = $src !== '' && EventRepository::assetRenderable($src);
        if ($hasFlyer) {
            if ($baseUrl !== '') $src = EventRepository::assetAbsUrl($src, rtrim($baseUrl, '/'));
            $alt = (string) ($flyer['alt'] ?? '');
            if ($alt === '') $alt = $brand . ': ' . $name . ' — ' . $meta; // flyer text, spoken
            $img = '<img src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy" decoding="async">';
            $webp = (string) ($flyer['webp'] ?? '');
            if ($webp !== '') {
                if ($baseUrl !== '') $webp = EventRepository::assetAbsUrl($webp, rtrim($baseUrl, '/'));
                $img = '<picture><source type="image/webp" srcset="' . h($webp) . '">' . $img . '</picture>';
            }
            $out .= $info !== ''
                ? '<a class="undr-event-card__media" href="' . h($info) . '"'
                    . ($own && !$past ? ' data-open-info="' . h($id) . '"' : '')
                    . '>' . $img . '</a>'
                : '<div class="undr-event-card__media">' . $img . '</div>';
        }

        $out .= '<div class="undr-event-card__body">';
        if (!$hasFlyer) {
            // No artwork to speak for the event — fall back to a text header.
            $out .= '<h3 class="undr-event-card__name">' . h($name) . '</h3>';
            $out .= '<p class="undr-event-card__meta">' . h($brand . ' · ' . $meta . ($venue !== '' ? ' · ' . $venue : '')) . '</p>';
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
        // 'loader'/'widget' (rausgegangen external-loader.js URL) → shared
        // tickets modal, with the plain href as the no-JS / no-modal fallback.
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
    // Event resolution
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
    // Event derived bits
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
     * UI label: the site catalog's key wins when defined (t() falls back to
     * the key itself when missing), else a built-in en/de default — so the
     * blocks work with zero per-site config but stay brand-overridable.
     */
    private static function label(string $key, string $lang): string
    {
        $t = t($key);
        if ($t !== $key) return $t;
        $defaults = [
            'blog_event_tickets'   => ['en' => 'Buy Tickets', 'de' => 'Tickets kaufen'],
            'blog_event_more_info' => ['en' => 'More Info',   'de' => 'Mehr Infos'],
            'blog_event_past'      => ['en' => 'This event has taken place.', 'de' => 'Dieses Event hat bereits stattgefunden.'],
            'blog_video_play'      => ['en' => 'Play video',  'de' => 'Video abspielen'],
            'blog_map_open'        => ['en' => 'Open map',    'de' => 'Karte öffnen'],
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
