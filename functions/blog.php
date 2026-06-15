<?php
declare(strict_types=1);

use Undr\Core\View\BlogRepository;

// ---------------------------------------------------------------------------
// Global blog-helper shims → delegate to BlogRepository. function_exists-guarded
// so a site keeps any brand-local override. Posts are served pre-rendered: each
// carries a sanitized `bodyHtml` (output it directly) and its source
// `bodyMarkdown`. Sites need no Markdown parser.
//
//   $posts = load_blog_posts($lang);          // newest-first, all published
//   $posts = load_blog_posts($lang, 3);       // newest 3 (e.g. a homepage rail)
//   $post  = load_blog_post($slug, $lang);    // one post, or null
//   $ld    = build_blog_jsonld($post, $lang, 'https://brand.tld');
// ---------------------------------------------------------------------------

if (!function_exists('load_blog_posts')) {
    function load_blog_posts(string $lang, ?int $cap = null): array
    {
        return BlogRepository::load($lang, $cap);
    }
}

if (!function_exists('load_blog_post')) {
    function load_blog_post(string $slug, string $lang): ?array
    {
        return BlogRepository::find($slug, $lang);
    }
}

if (!function_exists('build_blog_jsonld')) {
    /**
     * schema.org BlogPosting for a synced post. Brand-uniform (unlike the
     * per-brand build_event_jsonld), parameterized by the brand site base URL
     * and an optional org/site map (`['undr' => 'https://undr.zone']` or
     * `['org' => [...schema.org Organization...]]`).
     */
    function build_blog_jsonld(array $p, string $lang, string $baseUrl, array $site = []): array
    {
        $base = rtrim($baseUrl, '/');
        $url  = $base . '/' . ($p['slug'] ?? '') . '/';

        $img = $p['image']['webp'] ?? $p['image']['src'] ?? null;
        if (is_string($img) && $img !== '' && !preg_match('~^https?://~i', $img)) {
            $img = $base . $img; // local /media path → absolute
        }

        $org = $site['org'] ?? [
            '@type' => 'Organization',
            'name'  => 'UNDR',
            'url'   => $site['undr'] ?? 'https://undr.zone',
        ];

        $author = $p['author'] ?? null;
        if (is_string($author) && $author !== '') {
            $authorObj = ['@type' => 'Person', 'name' => $author];
        } elseif (is_array($author) && !empty($author['name'])) {
            $authorObj = ['@type' => 'Person', 'name' => (string) $author['name']];
            if (!empty($author['url'])) $authorObj['url'] = (string) $author['url'];
        } else {
            $authorObj = $org;
        }

        $out = [
            '@context'         => 'https://schema.org',
            '@type'            => 'BlogPosting',
            'headline'         => (string) ($p['title'] ?? ''),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'url'              => $url,
            'author'           => $authorObj,
            'publisher'        => $org,
            'inLanguage'       => $lang,
        ];
        if (!empty($p['excerpt'])) $out['description'] = (string) $p['excerpt'];
        if (!empty($p['date']))    $out['datePublished'] = (string) $p['date'];
        if (is_string($img) && $img !== '') $out['image'] = $img;
        $tags = array_values(array_filter((array) ($p['tags'] ?? []), fn($t) => is_string($t) && $t !== ''));
        if ($tags !== []) $out['keywords'] = implode(', ', $tags);

        return $out;
    }
}
