<?php
declare(strict_types=1);

namespace Undr\Core\View;

use Undr\Core\Site;

// ---------------------------------------------------------------------------
// Event loader + cache/asset resolution — shared by every site's start.php and
// sitemap.php. Data source: the local cache populated by the UNDR sync module
// (bin/sync.php → .cache/undr/events.<lang>.json). UNDR.zone is the source of
// truth; sites render pre-merged snapshots only. If the cache is absent (fresh
// deploy before the first sync, or API + cache both gone), load() returns [] and
// the page shows its graceful "coming soon" state — never an error.
// ---------------------------------------------------------------------------
final class EventRepository
{
    public static function readJson(string $file): ?array
    {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Resolve the sync cache dir (cached). Null if no config/cacheDir is found. */
    public static function cacheDir(): ?string
    {
        static $dir = false;
        if ($dir !== false) return $dir; // already resolved (incl. null)

        $explicit = Site::get('cacheDir');
        if (is_string($explicit) && $explicit !== '') { $dir = rtrim($explicit, '/'); return $dir; }

        // Fall back to the site's config/undr.php (one level above public/).
        $cfgFile = dirname(Site::publicDir()) . '/config/undr.php';
        if (!is_file($cfgFile)) { $dir = null; return null; }
        $cfg = require $cfgFile;
        $dir = (is_array($cfg) && !empty($cfg['cacheDir'])) ? rtrim($cfg['cacheDir'], '/') : null;
        return $dir;
    }

    /**
     * Upcoming events for $lang, read from the synced cache snapshot.
     * Falls back to the primary-language (en) snapshot if the requested one is
     * missing, then applies the consumer-side past-filter / sort / cap — the
     * consumer's clock is the authority for "upcoming". $cap null = all events.
     */
    public static function load(string $lang, \DateTimeZone $tz, ?int $cap = null): array
    {
        $cacheDir = self::cacheDir();
        if ($cacheDir === null) return [];

        $events = self::readJson($cacheDir . '/events.' . $lang . '.json');
        if ($events === null && $lang !== 'en') {
            $events = self::readJson($cacheDir . '/events.en.json'); // language fallback
        }
        if (!is_array($events)) return [];

        return self::filterSortCap($events, $tz, $cap);
    }

    /** Future-only, sorted ascending by date, optionally capped; drops malformed records. */
    public static function filterSortCap(array $events, \DateTimeZone $tz, ?int $cap = null): array
    {
        $today = (new \DateTime('today', $tz))->format('Y-m-d');
        $out = [];
        foreach ($events as $e) {
            if (!is_array($e)) continue;
            if (empty($e['id']) || empty($e['date']) || empty($e['name'])) continue;
            if (empty($e['venue']['name']) || empty($e['shortDescription'])) continue;
            if ($e['date'] < $today) continue;
            $out[] = $e;
        }
        usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $cap === null ? $out : array_slice($out, 0, $cap);
    }

    /**
     * True if an asset src is renderable: an absolute http(s) URL (trusted — e.g.
     * an un-mirrored UNDR media URL) OR an existing local file under public/.
     */
    public static function assetRenderable(?string $src): bool
    {
        if ($src === null || $src === '') return false;
        if (preg_match('~^https?://~i', $src)) return true;
        return is_file(Site::publicDir() . $src);
    }

    /** Resolve an asset src to an absolute URL for og:image / JSON-LD (absolute passes through). */
    public static function assetAbsUrl(string $src, string $baseUrl): string
    {
        return preg_match('~^https?://~i', $src) ? $src : $baseUrl . $src;
    }
}
