<?php
declare(strict_types=1);

namespace Undr\Core\View;

// ---------------------------------------------------------------------------
// Small derived-value helpers shared verbatim by all UNDR sites. The richer,
// brand-divergent derivations (JSON-LD projection, ticket-link widget derivation,
// timetable, ticket phases, FAQ, …) intentionally stay in each site's
// public/lib/events.brand.php — they have evolved differently per brand and
// forcing a single version would change a brand's rendered output.
// ---------------------------------------------------------------------------
final class EventDerive
{
    /**
     * All artists of one lineup slot, lead first then `b2b` partners in billing
     * order. A slot without (or with an empty) `b2b` array is a solo set.
     */
    public static function lineupSetArtists(array $dj): array
    {
        $partners = is_array($dj['b2b'] ?? null) ? array_filter($dj['b2b'], 'is_array') : [];
        return array_merge([$dj], array_values($partners));
    }

    public static function eventDt(array $e, string $key, \DateTimeZone $tz): \DateTime
    {
        $time = $e[$key] ?? ($key === 'doorsOpen' ? '23:00' : '12:00');
        $d = new \DateTime($e['date'] . 'T' . $time . ':00', $tz);
        if ($key === 'endTime' && !empty($e['endsNextDay'])) $d->modify('+1 day');
        return $d;
    }

    public static function lowestPrice(array $e): ?array
    {
        $tiers = $e['tickets'] ?? [];
        if (!$tiers) return null;
        usort($tiers, fn($a, $b) => ($a['price'] ?? 0) <=> ($b['price'] ?? 0));
        return $tiers[0];
    }
}
