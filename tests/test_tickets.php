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
ok('seb: stale -> false',              !RaTickets::superEarlyBird($e, ['2378967' => ['superEarlyBird' => true, 'checkedAt' => $now - RaTickets::STALE_AFTER - 1]], $now));
ok('seb: unknown RA event -> false',   !RaTickets::superEarlyBird($e, [], $now));
ok('seb: no RA link -> false',         !RaTickets::superEarlyBird(['ticketLinks' => [$e['ticketLinks'][0]]], $on, $now));

// --- Weezevent -----------------------------------------------------------------
ok('weez: widget URL is the loader',   Weezevent::widget($e['ticketLinks'][0]) === $weez);
ok('weez: de -> locale=de-DE',         str_contains((string) Weezevent::widget($e['ticketLinks'][0], 'de'), '?code=42706&locale=de-DE&'));
ok('weez: shop URL -> null',           Weezevent::widget(['provider' => 'weezevent', 'url' => 'https://my.weezevent.com/x']) === null);
ok('weez: other provider -> null',     Weezevent::widget(['provider' => 'ra', 'url' => $weez]) === null);

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";
exit($fail === 0 ? 0 : 1);
