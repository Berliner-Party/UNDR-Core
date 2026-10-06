<?php
declare(strict_types=1);

namespace Undr\Core\Tickets;

use Undr\Core\Http\UndrHttp;
use Undr\Core\View\EventRepository;

// ---------------------------------------------------------------------------
// Resident Advisor ticket-tier status — powers the brand rule "while RA still
// sells Super Early Bird, RA is the primary Buy link" (each brand's
// primary_ticket_link() decides whether/how to apply it).
//
// A brand's bin/sync.php calls refresh() after the UNDR sync: for every upcoming
// event with an ra.co link it asks RA's public GraphQL endpoint for the ticket
// tiers and writes <cache>/ra/tickets.json (sibling of .cache/undr). Throttled to
// REFRESH_EVERY seconds. Render time only READS that file — no visitor waits on
// RA. A missing, failed or stale (> STALE_AFTER) status means "no override", so a
// site falls back to its normal primary link and never breaks on RA.
// ---------------------------------------------------------------------------
final class RaTickets
{
    public const REFRESH_EVERY = 300;   // seconds between RA polls
    public const STALE_AFTER   = 1800;  // ignore a status older than this

    /** RA event id from an ra.co event URL ("https://ra.co/events/2378967"), else null. */
    public static function eventId(string $url): ?int
    {
        return preg_match('~^https?://(?:www\.)?ra\.co/events/(\d+)~i', $url, $m) ? (int) $m[1] : null;
    }

    /** Status file for a given UNDR cache dir (.cache/undr → .cache/ra/tickets.json). */
    public static function file(string $undrCacheDir): string
    {
        return dirname(rtrim($undrCacheDir, '/')) . '/ra/tickets.json';
    }

    /** Cached status map: raId => ['superEarlyBird' => bool, 'checkedAt' => int]. */
    public static function status(): array
    {
        static $data = null;
        if ($data !== null) return $data;
        $dir  = EventRepository::cacheDir();
        $json = $dir !== null ? EventRepository::readJson(self::file($dir)) : null;
        $data = is_array($json['events'] ?? null) ? $json['events'] : [];
        return $data;
    }

    /**
     * True while the event's RA listing has a Super Early Bird tier on sale (fresh
     * status only). $status/$now default to the status file / time() (tests inject).
     */
    public static function superEarlyBird(array $e, ?array $status = null, ?int $now = null): bool
    {
        $status ??= self::status();
        $now    ??= time();
        foreach ($e['ticketLinks'] ?? [] as $l) {
            if (($l['provider'] ?? '') !== 'ra' || empty($l['url'])) continue;
            $id = self::eventId((string) $l['url']);
            $st = $id !== null ? ($status[(string) $id] ?? null) : null;
            if (!is_array($st) || $now - (int) ($st['checkedAt'] ?? 0) > self::STALE_AFTER) return false;
            return !empty($st['superEarlyBird']);
        }
        return false;
    }

    /** The event's first ra.co ticket link, or null. */
    public static function link(array $e): ?array
    {
        foreach ($e['ticketLinks'] ?? [] as $l) {
            if (($l['provider'] ?? '') === 'ra' && !empty($l['url'])) return $l;
        }
        return null;
    }

    /**
     * Poll RA for every upcoming event (from the UNDR events cache) that has an RA
     * link and write the status file. Throttled to REFRESH_EVERY unless $force. A
     * failed lookup keeps that event's previous status. Returns a log line.
     */
    public static function refresh(string $undrCacheDir, bool $force = false, string $tz = 'Europe/Berlin'): string
    {
        $file = self::file($undrCacheDir);
        if (!$force && is_file($file) && time() - (int) filemtime($file) < self::REFRESH_EVERY) {
            return 'ra-tickets: fresh, skipped';
        }
        $events = EventRepository::readJson(rtrim($undrCacheDir, '/') . '/events.en.json');
        if ($events === null) return 'ra-tickets: no events cache, skipped';

        @mkdir(dirname($file), 0775, true);
        $lock = @fopen(dirname($file) . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return 'ra-tickets: locked, skipped';
        }
        try {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
            $ids = [];
            foreach ($events as $e) {
                if (!is_array($e) || ($e['date'] ?? '') < $today) continue;
                foreach ($e['ticketLinks'] ?? [] as $l) {
                    $id = ($l['provider'] ?? '') === 'ra' ? self::eventId((string) ($l['url'] ?? '')) : null;
                    if ($id !== null) $ids[$id] = true;
                }
            }

            $old  = EventRepository::readJson($file)['events'] ?? [];
            $http = new UndrHttp(8, 1, ['Referer: https://ra.co/']); // RA's edge rejects referer-less API calls
            $out  = [];
            $ok   = 0;
            foreach (array_keys($ids) as $id) {
                $query = '{ event(id: ' . $id . ') { tickets(queryType: AVAILABLE) { title validType } } }';
                $res   = $http->get('https://ra.co/graphql?query=' . rawurlencode($query));
                $tix   = $res->ok() ? (json_decode($res->body, true)['data']['event']['tickets'] ?? null) : null;
                if (!is_array($tix)) {
                    if (isset($old[(string) $id])) $out[(string) $id] = $old[(string) $id]; // keep last-good
                    continue;
                }
                $seb = false;
                foreach ($tix as $t) {
                    if (preg_match('/super\s*early/i', (string) ($t['title'] ?? '')) && ($t['validType'] ?? '') === 'VALID') {
                        $seb = true;
                    }
                }
                $out[(string) $id] = ['superEarlyBird' => $seb, 'checkedAt' => time()];
                $ok++;
            }

            $tmp = $file . '.tmp';
            file_put_contents($tmp, json_encode(['events' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            rename($tmp, $file);
            return sprintf('ra-tickets: %d/%d RA event(s) checked', $ok, count($ids));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
