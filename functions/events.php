<?php
declare(strict_types=1);

use Undr\Core\View\EventRepository;
use Undr\Core\View\EventDerive;

// ---------------------------------------------------------------------------
// Global event-helper shims → delegate to the View classes. function_exists-
// guarded so a site keeps any brand-local override. Only the helpers that are
// byte-identical across HEAT / CAGE / UNLEASHED live here; brand-divergent
// helpers (build_event_jsonld, primary_ticket_link, alt_ticket_links, the .ics
// builder, timetable, ticket phases, FAQ, …) stay in public/lib/events.brand.php.
//
// NOTE on load_events()/undr_filter_sort_cap(): the shared signature takes an
// optional $cap (null = all). HEAT and CAGE historically capped at 5 — their
// call sites must pass 5 explicitly (load_events($lang, $tz, 5)). UNLEASHED
// already calls without a cap to list the whole season.
// ---------------------------------------------------------------------------

if (!function_exists('undr_read_json')) {
    function undr_read_json(string $file): ?array { return EventRepository::readJson($file); }
}

if (!function_exists('undr_cache_dir')) {
    function undr_cache_dir(): ?string { return EventRepository::cacheDir(); }
}

if (!function_exists('load_events')) {
    function load_events(string $lang, DateTimeZone $tz, ?int $cap = null): array
    {
        return EventRepository::load($lang, $tz, $cap);
    }
}

if (!function_exists('undr_filter_sort_cap')) {
    function undr_filter_sort_cap(array $events, DateTimeZone $tz, ?int $cap = null): array
    {
        return EventRepository::filterSortCap($events, $tz, $cap);
    }
}

if (!function_exists('asset_renderable')) {
    function asset_renderable(?string $src): bool { return EventRepository::assetRenderable($src); }
}

if (!function_exists('asset_abs_url')) {
    function asset_abs_url(string $src, string $baseUrl): string { return EventRepository::assetAbsUrl($src, $baseUrl); }
}

if (!function_exists('lineup_set_artists')) {
    function lineup_set_artists(array $dj): array { return EventDerive::lineupSetArtists($dj); }
}

if (!function_exists('event_dt')) {
    function event_dt(array $e, string $key, DateTimeZone $tz): DateTime { return EventDerive::eventDt($e, $key, $tz); }
}

if (!function_exists('lowest_price')) {
    function lowest_price(array $e): ?array { return EventDerive::lowestPrice($e); }
}

// alt_ticket_links is byte-identical across HEAT/UNLEASHED and was missing on
// CAGE; promoted here so every site (incl. the buy-tickets page) and future
// brands get the same "also available on …" list. It calls primary_ticket_link
// (which STAYS per-brand — CAGE intentionally omits the rausgegangen widget
// loader) and the Core i18n helper t(); the labels open_ticket_shop /
// tickets_generic must exist in each brand's lang catalog.
if (!function_exists('alt_ticket_links')) {
    function alt_ticket_links(array $e): array
    {
        $primary    = function_exists('primary_ticket_link') ? primary_ticket_link($e) : ($e['ticketLinks'][0] ?? null);
        $primaryUrl = $primary['url'] ?? null;
        $providerLabels = ['ra' => 'Resident Advisor', 'rausgegangen' => 'Rausgegangen'];

        $alts = [];
        if ($primaryUrl) {
            $alts[] = [
                'label' => $providerLabels[$primary['provider'] ?? ''] ?? t('open_ticket_shop'),
                'url'   => $primaryUrl,
            ];
        }
        foreach ($e['ticketLinks'] ?? [] as $l) {
            if (empty($l['url']) || $l['url'] === $primaryUrl) continue;
            $alts[] = [
                'label' => $providerLabels[$l['provider'] ?? ''] ?? ucfirst($l['provider'] ?? t('tickets_generic')),
                'url'   => $l['url'],
            ];
        }
        return $alts;
    }
}
