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
// A brand's bin/sync.php calls refresh() after every UNDR sync. Each upcoming
// event with an ra.co link is looked up on RA's public GraphQL endpoint only when
// it is due (see nextCheck()): every 10 days until 30 days out, every 5 days until
// 20 days out, then every 3 days — and never again once its Super Early Bird tier
// is gone (sold out / removed). Results live in <cache>/ra/tickets.json (sibling
// of .cache/undr); render time only READS that file, so no visitor waits on RA.
// A missing or stale (> STALE_AFTER: the cron/RA has been failing for days)
// status means "no override" — sites fall back to their normal primary link.
// ---------------------------------------------------------------------------
final class RaTickets
{
    public const STALE_AFTER = 12 * 86400;  // longest interval (10 d) + 2 d grace
    public const RETRY_AFTER = 3600;        // failed lookup → try again in an hour

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

    /** Cached status map: raId => ['superEarlyBird' => bool, 'final' => bool, 'checkedAt' => int, 'nextCheck' => int]. */
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
     * True while the event's RA listing has a Super Early Bird tier on sale (as of
     * the last check, if not stale). $status/$now default to the status file /
     * time() (tests inject).
     */
    public static function superEarlyBird(array $e, ?array $status = null, ?int $now = null): bool
    {
        $status ??= self::status();
        $now    ??= time();
        foreach ($e['ticketLinks'] ?? [] as $l) {
            if (($l['provider'] ?? '') !== 'ra' || empty($l['url'])) continue;
            $id = self::eventId((string) $l['url']);
            $st = $id !== null ? ($status[(string) $id] ?? null) : null;
            if (!is_array($st) || !empty($st['final'])) return false;
            if ($now - (int) ($st['checkedAt'] ?? 0) > self::STALE_AFTER) return false;
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
     * When to look again after a check at $now for an event on $eventDate:
     * +10 d while more than 30 days out, +5 d while more than 20 days out, else
     * +3 d — capped at the 30-/20-day marks so each phase starts on time.
     */
    public static function nextCheck(int $now, string $eventDate, string $tz = 'Europe/Berlin'): int
    {
        $event = (new \DateTimeImmutable($eventDate, new \DateTimeZone($tz)))->getTimestamp();
        $days  = ($event - $now) / 86400;
        if ($days > 30) return min($now + 10 * 86400, $event - 30 * 86400);
        if ($days > 20) return min($now + 5 * 86400, $event - 20 * 86400);
        return $now + 3 * 86400;
    }

    /**
     * Read RA's tier list: ['onSale' => any Super Early Bird tier VALID,
     * 'gone' => no Super Early Bird tier left that could still sell (none listed,
     * or every one SOLDOUT)]. A tier in any other state (e.g. not yet on sale)
     * keeps it not-gone.
     */
    public static function superEarlyBirdState(array $tickets): array
    {
        $onSale = false;
        $gone   = true;
        foreach ($tickets as $t) {
            if (!preg_match('/super\s*early/i', (string) ($t['title'] ?? ''))) continue;
            $type = (string) ($t['validType'] ?? '');
            if ($type === 'VALID') $onSale = true;
            if ($type !== 'SOLDOUT') $gone = false;
        }
        return ['onSale' => $onSale, 'gone' => $gone];
    }

    /**
     * Look up every upcoming RA-linked event (from the UNDR events cache) that is
     * due and update the status file. $force re-checks everything, final ones too.
     * A failed lookup keeps the previous status and retries after RETRY_AFTER.
     * Returns a log line.
     */
    public static function refresh(string $undrCacheDir, bool $force = false, string $tz = 'Europe/Berlin'): string
    {
        $events = EventRepository::readJson(rtrim($undrCacheDir, '/') . '/events.en.json');
        if ($events === null) return 'ra-tickets: no events cache, skipped';

        $file = self::file($undrCacheDir);
        @mkdir(dirname($file), 0775, true);
        $lock = @fopen(dirname($file) . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            return 'ra-tickets: locked, skipped';
        }
        try {
            $now   = time();
            $today = (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
            $dates = []; // raId => event date
            foreach ($events as $e) {
                if (!is_array($e) || ($e['date'] ?? '') < $today) continue;
                foreach ($e['ticketLinks'] ?? [] as $l) {
                    $id = ($l['provider'] ?? '') === 'ra' ? self::eventId((string) ($l['url'] ?? '')) : null;
                    if ($id !== null) $dates[$id] = (string) $e['date'];
                }
            }

            $old  = EventRepository::readJson($file)['events'] ?? [];
            $http = null;
            $out  = [];
            $checked = $failed = 0;
            foreach ($dates as $id => $date) {
                $prev = $old[(string) $id] ?? null;
                $due  = $force || !is_array($prev)
                    || (empty($prev['final']) && $now >= (int) ($prev['nextCheck'] ?? 0));
                if (!$due) { $out[(string) $id] = $prev; continue; }

                $http ??= new UndrHttp(8, 1, ['Referer: https://ra.co/']); // RA's edge rejects referer-less API calls
                $query = '{ event(id: ' . $id . ') { tickets(queryType: AVAILABLE) { title validType } } }';
                $res   = $http->get('https://ra.co/graphql?query=' . rawurlencode($query));
                $tix   = $res->ok() ? (json_decode($res->body, true)['data']['event']['tickets'] ?? null) : null;
                if (!is_array($tix)) {
                    $keep = is_array($prev) ? $prev : ['superEarlyBird' => false, 'final' => false, 'checkedAt' => 0];
                    $out[(string) $id] = ['nextCheck' => $now + self::RETRY_AFTER] + $keep;
                    $failed++;
                    continue;
                }
                $state = self::superEarlyBirdState($tix);
                $out[(string) $id] = [
                    'superEarlyBird' => $state['onSale'],
                    'final'          => $state['gone'],
                    'checkedAt'      => $now,
                    'nextCheck'      => $state['gone'] ? null : self::nextCheck($now, $date, $tz),
                ];
                $checked++;
            }

            if ($out != $old) {
                $tmp = $file . '.tmp';
                file_put_contents($tmp, json_encode(['events' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                rename($tmp, $file);
            }
            return sprintf('ra-tickets: %d checked, %d failed, %d not due (of %d RA event(s))',
                $checked, $failed, count($dates) - $checked - $failed, count($dates));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
