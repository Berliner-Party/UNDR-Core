<?php
declare(strict_types=1);

// Plain-PHP tests for the ticket helpers (no framework, no network).
//   php tests/test_tickets.php
// RaTickets: event-id parsing + the Super Early Bird status check (injected
// status map). Weezevent: widget-URL detection + de locale switch.

$root = dirname(__DIR__);
require $root . '/src/Site.php';
require $root . '/src/View/EventRepository.php';
require $root . '/src/Http/UndrHttp.php';
require $root . '/src/Tickets/RaTickets.php';
require $root . '/src/Tickets/Weezevent.php';
require $root . '/src/View/Catalog.php';
require $root . '/functions/i18n.php';
require $root . '/functions/events.php';

use Undr\Core\Tickets\RaTickets;
use Undr\Core\Tickets\Weezevent;

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$weez = 'https://widget.weezevent.com/ticket/E2428493/?code=42706&locale=en-GB&width_auto=1';
$ra   = 'https://ra.co/events/2378967';
$e    = ['ticketLinks' => [
    ['provider' => 'weezevent', 'url' => $weez, 'primary' => true],
    ['provider' => 'ra', 'url' => $ra],
]];
$now = 1_800_000_000;

// --- RaTickets ------------------------------------------------------------------
ok('ra id: parsed from event URL',     RaTickets::eventId($ra) === 2378967);
ok('ra id: www + trailing path ok',    RaTickets::eventId('https://www.ra.co/events/42/tickets') === 42);
ok('ra id: non-RA URL -> null',        RaTickets::eventId($weez) === null);
ok('ra link: finds the RA entry',      (RaTickets::link($e)['url'] ?? null) === $ra);
ok('ra link: none -> null',            RaTickets::link(['ticketLinks' => [$e['ticketLinks'][0]]]) === null);
ok('file: sibling of .cache/undr',     RaTickets::file('/x/.cache/undr/') === '/x/.cache/ra/tickets.json');

$on  = ['2378967' => ['superEarlyBird' => true,  'checkedAt' => $now - 60]];
$off = ['2378967' => ['superEarlyBird' => false, 'checkedAt' => $now - 60]];
ok('seb: on sale + fresh -> true',     RaTickets::superEarlyBird($e, $on, $now));
ok('seb: sold out -> false',           !RaTickets::superEarlyBird($e, $off, $now));
ok('seb: 9-day-old check still counts', RaTickets::superEarlyBird($e, ['2378967' => ['superEarlyBird' => true, 'checkedAt' => $now - 9 * 86400]], $now));
ok('seb: stale -> false',              !RaTickets::superEarlyBird($e, ['2378967' => ['superEarlyBird' => true, 'checkedAt' => $now - RaTickets::STALE_AFTER - 1]], $now));
ok('seb: final -> false',              !RaTickets::superEarlyBird($e, ['2378967' => ['superEarlyBird' => true, 'final' => true, 'checkedAt' => $now - 60]], $now));
ok('seb: unknown RA event -> false',   !RaTickets::superEarlyBird($e, [], $now));
ok('seb: no RA link -> false',         !RaTickets::superEarlyBird(['ticketLinks' => [$e['ticketLinks'][0]]], $on, $now));

// schedule: 10 d until 30 d out, 5 d until 20 d out, then 3 d (capped at the marks)
$tz  = new DateTimeZone('Europe/Berlin');
$at  = fn(string $d) => (new DateTimeImmutable($d, $tz))->getTimestamp();
$ev  = '2026-12-26';
ok('next: 60 d out -> +10 d',          RaTickets::nextCheck($at('2026-10-27'), $ev) === $at('2026-11-06'));
ok('next: 35 d out -> capped at 30 d', RaTickets::nextCheck($at('2026-11-21'), $ev) === $at('2026-11-26'));
ok('next: 30 d out -> +5 d',           RaTickets::nextCheck($at('2026-11-26'), $ev) === $at('2026-12-01'));
ok('next: 23 d out -> capped at 20 d', RaTickets::nextCheck($at('2026-12-03'), $ev) === $at('2026-12-06'));
ok('next: 20 d out -> +3 d',           RaTickets::nextCheck($at('2026-12-06'), $ev) === $at('2026-12-09'));

// Super Early Bird state from RA's tier list
$st = fn(array $types) => RaTickets::superEarlyBirdState(array_map(fn($t) => ['title' => 'Super Early Bird', 'validType' => $t], $types) + [99 => ['title' => 'Early bird', 'validType' => 'VALID']]);
ok('state: VALID -> on sale, not gone', $st(['VALID']) === ['onSale' => true, 'gone' => false]);
ok('state: SOLDOUT -> gone',            $st(['SOLDOUT']) === ['onSale' => false, 'gone' => true]);
ok('state: no SEB tier -> gone',        $st([]) === ['onSale' => false, 'gone' => true]);
ok('state: not yet on sale -> waiting', $st(['UPCOMING']) === ['onSale' => false, 'gone' => false]);

// --- Weezevent -----------------------------------------------------------------
ok('weez: widget URL is the loader',   Weezevent::widget($e['ticketLinks'][0]) === $weez);
ok('weez: de -> locale=de-DE',         str_contains((string) Weezevent::widget($e['ticketLinks'][0], 'de'), '?code=42706&locale=de-DE&'));
ok('weez: shop URL -> null',           Weezevent::widget(['provider' => 'weezevent', 'url' => 'https://my.weezevent.com/x']) === null);
ok('weez: params forced (set+replace)', Weezevent::widget(['provider' => 'weezevent', 'url' => $weez . '&color_primary=0032FA'], 'en', ['o' => 'bounce', 'color_primary' => 'C4168F']) === $weez . '&color_primary=C4168F&o=bounce');
ok('weez: de + params',                Weezevent::widget($e['ticketLinks'][0], 'de', ['o' => 'x']) === str_replace('en-GB', 'de-DE', $weez) . '&o=x');
ok('weez: other provider -> null',     Weezevent::widget(['provider' => 'ra', 'url' => $weez]) === null);

// --- alt_ticket_links: an embedded primary is not repeated -------------------------
function primary_ticket_link(array $e): ?array {
    $l = $e['ticketLinks'][0] ?? null;
    if ($l && ($w = Weezevent::widget($l)) !== null) $l['widget'] = $w;
    return $l;
}
ok('alts: embedded weezevent -> RA only', array_column(alt_ticket_links($e), 'label') === ['Resident Advisor']);
ok('alts: plain primary still listed',    array_column(alt_ticket_links(['ticketLinks' => [['provider' => 'ra', 'url' => $ra], $e['ticketLinks'][0]]]), 'label') === ['Resident Advisor', 'Weezevent']);

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
exit($fail === 0 ? 0 : 1);
