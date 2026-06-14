<?php
declare(strict_types=1);

namespace Undr\Core;

// ---------------------------------------------------------------------------
// Per-site configuration holder.
//
// Each consuming site calls Site::configure([...]) ONCE near the top of its
// entry script (start.php / sitemap.php / impressum.php) so the global view
// helper shims (h(), t(), load_events(), asset_renderable(), …) can resolve the
// site's public dir, cache dir and brand without threading them through every
// one of the hundreds of template call sites. This is the single bootstrap line
// that replaces the old `require_once lib/i18n.php; require_once lib/events.php`.
// ---------------------------------------------------------------------------
final class Site
{
    /** @var array<string,mixed> */
    private static array $cfg = [];

    /** Merge config in; later keys win, earlier values are preserved. */
    public static function configure(array $cfg): void
    {
        self::$cfg = $cfg + self::$cfg;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$cfg[$key] ?? $default;
    }

    /** Absolute path to the site's web root (the directory holding start.php). */
    public static function publicDir(): string
    {
        return (string) (self::$cfg['publicDir'] ?? getcwd());
    }

    public static function brand(): string
    {
        return (string) (self::$cfg['brand'] ?? '');
    }
}
