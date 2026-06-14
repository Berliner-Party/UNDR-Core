<?php
declare(strict_types=1);

use Undr\Core\View\Catalog;

// ---------------------------------------------------------------------------
// Localized date formatting — replace DateTime's English weekday/month tokens
// with the catalog's hand tables (no intl dependency). function_exists-guarded.
// Identical across all UNDR sites.
// ---------------------------------------------------------------------------

if (!function_exists('loc_weekday_short')) {
    function loc_weekday_short(DateTime $d): string
    {
        $t = Catalog::table('daysShort', ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']);
        return $t[(int) $d->format('w')] ?? $d->format('D');
    }
}

if (!function_exists('loc_month_short')) {
    function loc_month_short(DateTime $d): string
    {
        $t = Catalog::table('monthsShort', ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']);
        return $t[(int) $d->format('n')] ?? $d->format('M');
    }
}

if (!function_exists('loc_short_date')) {
    /** "16 MAY 2026" (hero day pill + upcoming list). */
    function loc_short_date(DateTime $d): string
    {
        return mb_strtoupper($d->format('d') . ' ' . loc_month_short($d) . ' ' . $d->format('Y'), 'UTF-8');
    }
}

if (!function_exists('loc_weekday_upper')) {
    /** "FRI" — uppercased localized weekday (hero). */
    function loc_weekday_upper(DateTime $d): string
    {
        return mb_strtoupper(loc_weekday_short($d), 'UTF-8');
    }
}

if (!function_exists('loc_doors_range')) {
    /** "Fri · 31 Jul · 23:00–06:00" (event-detail doors line). */
    function loc_doors_range(DateTime $start, DateTime $end): string
    {
        return loc_weekday_short($start) . ' · ' . $start->format('d') . ' ' . loc_month_short($start)
            . ' · ' . $start->format('H:i') . '–' . $end->format('H:i');
    }
}

if (!function_exists('loc_tix_datetime')) {
    /** "Fri, 31 Jul 2026 · 23:00" (tickets-modal header context). */
    function loc_tix_datetime(DateTime $d): string
    {
        return loc_weekday_short($d) . ', ' . $d->format('d') . ' ' . loc_month_short($d) . ' ' . $d->format('Y') . ' · ' . $d->format('H:i');
    }
}
