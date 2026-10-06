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
     * widget URL. $lang 'de' switches the widget's locale to de-DE. $params are
     * forced onto the widget query (set or replaced) — brands pass their origin
     * tag and color, e.g. ['o' => 'unleashed', 'color_primary' => '7A00DF'], so a
     * pasted default embed still renders on-brand and attributes sales.
     */
    public static function widget(array $link, string $lang = 'en', array $params = []): ?string
    {
        $url = (string) ($link['url'] ?? '');
        if (($link['provider'] ?? '') !== 'weezevent' || !preg_match('~^https://widget\.weezevent\.com/~i', $url)) {
            return null;
        }
        if ($lang === 'de') $params += ['locale' => 'de-DE'];
        return self::withParams($url, $params);
    }

    /** Set or replace query parameters, leaving the rest of the URL untouched. */
    public static function withParams(string $url, array $params): string
    {
        foreach ($params as $k => $v) {
            $pair = $k . '=' . rawurlencode((string) $v);
            $re   = '~([?&])' . preg_quote((string) $k, '~') . '=[^&#]*~';
            $url  = preg_match($re, $url)
                ? preg_replace($re, '${1}' . $pair, $url, 1)
                : $url . (str_contains($url, '?') ? '&' : '?') . $pair;
        }
        return $url;
    }
}
