<?php
declare(strict_types=1);

namespace Undr\Core\Tickets;

// ---------------------------------------------------------------------------
// Weezevent ticket widgets. A ticketLinks entry with provider "weezevent" whose
// url is the widget URL from Weezevent's embed code (the data-src,
// https://widget.weezevent.com/ticket/…) is its own tickets-modal loader:
// undr-tickets.js renders it through Weezevent's weez.js. Brands with a strict
// CSP must allow https://widget.weezevent.com in script-src (frame-src too).
// ---------------------------------------------------------------------------
final class Weezevent
{
    /**
     * Tickets-modal loader for a ticket link, or null when it is not a Weezevent
     * widget URL. $lang 'de' switches the widget's locale to de-DE.
     */
    public static function widget(array $link, string $lang = 'en'): ?string
    {
        $url = (string) ($link['url'] ?? '');
        if (($link['provider'] ?? '') !== 'weezevent' || !preg_match('~^https://widget\.weezevent\.com/~i', $url)) {
            return null;
        }
        return $lang === 'de' ? preg_replace('~([?&]locale=)[A-Za-z_-]+~', '${1}de-DE', $url) : $url;
    }
}
