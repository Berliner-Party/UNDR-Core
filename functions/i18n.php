<?php
declare(strict_types=1);

use Undr\Core\Site;
use Undr\Core\View\Catalog;

// ---------------------------------------------------------------------------
// Global i18n shims → delegate to Undr\Core\View\Catalog. function_exists-guarded.
// ---------------------------------------------------------------------------

if (!function_exists('i18n_boot')) {
    /**
     * Load the UI catalog for $lang. $dir defaults to <site public>/lang, so the
     * existing call sites — i18n_boot($lang) — keep working unchanged once the
     * site has called Site::configure(['publicDir' => __DIR__]).
     */
    function i18n_boot(string $lang, ?string $dir = null): void
    {
        Catalog::boot($lang, $dir ?? (Site::publicDir() . '/lang'));
    }
}

if (!function_exists('current_lang')) {
    function current_lang(): string { return Catalog::lang(); }
}

if (!function_exists('t')) {
    /** Translate a UI key. Falls back to the key itself. Never escapes — escape at the sink with h(). */
    function t(string $key, ...$args): string { return Catalog::t($key, ...$args); }
}

if (!function_exists('seo_clip')) {
    /**
     * Clip a description to a SERP-friendly length (~160 chars): prefer ending on a
     * full sentence within the budget, else cut on a word boundary with an ellipsis.
     */
    function seo_clip(string $s, int $max = 160): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if (mb_strlen($s) <= $max) return $s;
        $limit = (int) round($max * 1.05);
        $out = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $s) as $sentence) {
            $candidate = $out === '' ? $sentence : $out . ' ' . $sentence;
            if (mb_strlen($candidate) > $limit) break;
            $out = $candidate;
        }
        if ($out !== '' && mb_strlen($out) >= (int) ($max * 0.6)) return $out;
        $cut  = mb_substr($s, 0, $max - 1);
        $word = preg_replace('/\s+\S*$/u', '', $cut);
        return ($word !== '' ? $word : $cut) . '…';
    }
}
