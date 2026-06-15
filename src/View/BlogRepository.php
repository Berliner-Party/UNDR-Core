<?php
declare(strict_types=1);

namespace Undr\Core\View;

// ---------------------------------------------------------------------------
// Blog loader — the blog counterpart to EventRepository. Reads the synced
// snapshot written by UndrSync (.cache/undr/blog.<lang>.json). Posts are
// authored in the UNDR portal, served pre-rendered (sanitized bodyHtml) by the
// API, and mirrored here. If the cache is absent (fresh deploy before the first
// sync), load() returns [] and the page shows its graceful empty state.
//
// Reuses EventRepository::cacheDir()/readJson() so the cache location is
// resolved once, the same way for events and posts.
// ---------------------------------------------------------------------------
final class BlogRepository
{
    /**
     * Published posts for $lang (newest first), read from the synced snapshot.
     * Falls back to the English snapshot if the requested language is missing.
     */
    public static function load(string $lang, ?int $cap = null): array
    {
        $cacheDir = EventRepository::cacheDir();
        if ($cacheDir === null) return [];

        $posts = EventRepository::readJson($cacheDir . '/blog.' . $lang . '.json');
        if ($posts === null && $lang !== 'en') {
            $posts = EventRepository::readJson($cacheDir . '/blog.en.json');
        }
        if (!is_array($posts)) return [];

        return self::sortCap($posts, $cap);
    }

    /** One published post by slug for $lang, or null if not found. */
    public static function find(string $slug, string $lang): ?array
    {
        foreach (self::load($lang) as $p) {
            if (($p['slug'] ?? null) === $slug) {
                return $p;
            }
        }
        return null;
    }

    /** Newest-first by date, optionally capped; drops malformed records. */
    public static function sortCap(array $posts, ?int $cap = null): array
    {
        $out = [];
        foreach ($posts as $p) {
            if (!is_array($p) || empty($p['slug']) || empty($p['title'])) continue;
            $out[] = $p;
        }
        usort($out, fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
        return $cap === null ? $out : array_slice($out, 0, $cap);
    }
}
