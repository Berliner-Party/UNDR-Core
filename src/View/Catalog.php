<?php
declare(strict_types=1);

namespace Undr\Core\View;

// ---------------------------------------------------------------------------
// i18n catalog — the single source of UI strings + weekday/month name tables
// for one request. Replaces the per-brand $GLOBALS['HEAT_CATALOG'] /
// ['CAGE_CATALOG'] / ['UNL_CATALOG'] globals with one shared implementation so
// every site's t() / current_lang() / loc_* helpers behave identically.
//
// The brand still owns its catalog DATA (public/lang/en.php, de.php); Catalog
// only loads and serves it.
// ---------------------------------------------------------------------------
final class Catalog
{
    /** @var array<string,mixed>|null */
    private static ?array $catalog = null;
    private static string $lang = 'en';

    /** Load the catalog for $lang from $dir (falls back to en.php). */
    public static function boot(string $lang, string $dir): void
    {
        $dir  = rtrim($dir, '/');
        $file = $dir . '/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
        if (!is_file($file)) { $file = $dir . '/en.php'; $lang = 'en'; }
        self::$catalog = require $file;
        self::$lang    = $lang;
    }

    public static function lang(): string { return self::$lang; }

    /** Translate a UI key. Falls back to the key itself (visible + debuggable). */
    public static function t(string $key, ...$args): string
    {
        $tpl = self::$catalog['ui'][$key] ?? $key;
        return $args ? vsprintf($tpl, $args) : $tpl;
    }

    /** A named table from the catalog (e.g. daysShort, monthsShort) or $default. */
    public static function table(string $name, array $default = []): array
    {
        $v = self::$catalog[$name] ?? null;
        return is_array($v) ? $v : $default;
    }

    /**
     * Raw catalog value (scalar or array) by key, for templates that read a
     * catalog entry directly (e.g. CAGE's ogLocale / countdown labels) — the
     * accessor that replaces the old $GLOBALS['<BRAND>_CATALOG'] direct reads.
     */
    public static function raw(string $key, mixed $default = null): mixed
    {
        return self::$catalog[$key] ?? $default;
    }
}
