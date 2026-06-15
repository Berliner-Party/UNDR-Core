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
//   $bc    = build_blog_breadcrumb($lang, 'https://brand.tld', $post);
//   $idx   = build_blog_index_jsonld($posts, $lang, 'https://brand.tld');
//   $map   = blog_sitemap_entries('https://brand.tld', $lang);   // <url> rows
//   $rss   = build_blog_feed($posts, $lang, 'https://brand.tld', 'UNLEASHED');
//   echo blog_image_tag($post['image'], ['fetchpriority' => 'high']);
//
// All blog JSON-LD/feed/sitemap output is brand-UNIFORM and parameterized by the
// brand base URL — only the per-brand EVENT JSON-LD lives in events.brand.php.
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

        // dateModified — folded into the synced body by the backend (BlogBuilder),
        // falling back to updatedAt then the publish date. Drives "freshness"
        // signals for SERP + AI-search crawlers.
        $modified = $p['dateModified'] ?? $p['updatedAt'] ?? $p['date'] ?? null;
        if (is_string($modified) && $modified !== '') $out['dateModified'] = $modified;

        if (is_string($img) && $img !== '') $out['image'] = $img;
        $tags = array_values(array_filter((array) ($p['tags'] ?? []), fn($t) => is_string($t) && $t !== ''));
        if ($tags !== []) $out['keywords'] = implode(', ', $tags);

        // GEO / AI-search readiness: a plain-text articleBody + wordCount let
        // answer engines quote the post directly, and a SpeakableSpecification
        // marks the headline/description for voice surfaces. Derived from the
        // sanitized bodyHtml so it stays byte-consistent with what's rendered.
        $plain = blog_plain_text((string) ($p['bodyHtml'] ?? ''));
        if ($plain !== '') {
            $out['articleBody'] = $plain;
            $out['wordCount']   = blog_word_count($plain);
        }
        // The post H1 class differs per brand (UNLEASHED .page-title;
        // HEAT/CAGE .undr-post__title), so mark the headline node by both the
        // known brand selectors and the bare h1 as a universal fallback. The
        // description rides in the JSON-LD itself.
        $out['speakable'] = [
            '@type'       => 'SpeakableSpecification',
            'cssSelector' => ['.page-title', '.undr-post__title', 'article h1'],
        ];

        return $out;
    }
}

if (!function_exists('blog_plain_text')) {
    /**
     * Plain-text projection of a sanitized bodyHtml: drop tags, decode entities,
     * collapse whitespace. Used for articleBody/wordCount and the feed fallbacks.
     */
    function blog_plain_text(string $html): string
    {
        if ($html === '') return '';
        // Give block boundaries a space so words don't run together once tags go.
        $text = preg_replace('~<(?:br|/p|/div|/li|/h[1-6])\b[^>]*>~i', ' ', $html);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

if (!function_exists('blog_word_count')) {
    /** Word count of a plain-text string (multibyte-safe enough for word totals). */
    function blog_word_count(string $plain): int
    {
        if ($plain === '') return 0;
        return count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}

if (!function_exists('build_blog_breadcrumb')) {
    /**
     * schema.org BreadcrumbList for a post page: Home › News (/news/) › <title>.
     * Lang-aware (German routes carry a /de prefix; the labels localize too). The
     * leaf uses the post title and points at the post's own URL.
     */
    function build_blog_breadcrumb(string $lang, string $baseUrl, array $post): array
    {
        $base   = rtrim($baseUrl, '/');
        $prefix = $lang === 'en' ? '' : '/' . $lang;          // /de/news/, /de/<slug>/
        $home   = $lang === 'de' ? 'Startseite' : 'Home';
        $news   = 'News';                                     // brand-uniform label
        $slug   = (string) ($post['slug'] ?? '');
        $title  = (string) ($post['title'] ?? $slug);

        $crumb = static function (int $pos, string $name, string $url): array {
            return [
                '@type'    => 'ListItem',
                'position' => $pos,
                'name'     => $name,
                'item'     => $url,
            ];
        };

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                $crumb(1, $home, $base . ($prefix !== '' ? $prefix . '/' : '/')),
                $crumb(2, $news, $base . $prefix . '/news/'),
                $crumb(3, $title, $base . $prefix . '/' . $slug . '/'),
            ],
        ];
    }
}

if (!function_exists('build_blog_index_jsonld')) {
    /**
     * schema.org Blog + an ItemList of its posts for the /news/ index. Entries are
     * lightweight ListItem rows (position, url, name) — enough for rich results
     * without duplicating each post's full BlogPosting.
     */
    function build_blog_index_jsonld(array $posts, string $lang, string $baseUrl): array
    {
        $base   = rtrim($baseUrl, '/');
        $prefix = $lang === 'en' ? '' : '/' . $lang;
        $newsUrl = $base . $prefix . '/news/';

        $items = [];
        $pos   = 0;
        foreach ($posts as $p) {
            if (!is_array($p) || empty($p['slug'])) continue;
            $pos++;
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos,
                'url'      => $base . $prefix . '/' . (string) $p['slug'] . '/',
                'name'     => (string) ($p['title'] ?? $p['slug']),
            ];
        }

        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'Blog',
            '@id'         => $newsUrl,
            'url'         => $newsUrl,
            'inLanguage'  => $lang,
            'blogPost'    => [
                '@type'           => 'ItemList',
                'itemListElement' => $items,
            ],
        ];
    }
}

if (!function_exists('blog_sitemap_entries')) {
    /**
     * Sitemap rows for every published post, so a brand sitemap can splice blog
     * URLs in. Paths are language-relative (the site prepends its own /<lang>
     * prefix and base host as it already does for static routes).
     *
     * @return array<int,array{path:string,lastmod:string,changefreq:string,priority:string}>
     */
    function blog_sitemap_entries(string $baseUrl, string $lang = 'en'): array
    {
        // $baseUrl is accepted for signature symmetry with the other helpers and
        // future absolute-loc needs; paths returned here stay site-relative.
        unset($baseUrl);
        $out = [];
        foreach (load_blog_posts($lang) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') continue;
            $lastmod = (string) ($p['dateModified'] ?? $p['date'] ?? '');
            $row = [
                'path'       => '/' . $slug . '/',
                'changefreq' => 'monthly',
                'priority'   => '0.6',
            ];
            if ($lastmod !== '') $row['lastmod'] = blog_iso8601($lastmod);
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('build_blog_feed')) {
    /**
     * A complete, dependency-free RSS 2.0 document for the /news/ feed. Channel
     * metadata + one <item> per post (title, link, guid, pubDate RFC-822,
     * description=excerpt, content:encoded=bodyHtml in CDATA). Well-formed:
     * everything is escaped or CDATA-wrapped, the content namespace is declared.
     */
    function build_blog_feed(array $posts, string $lang, string $baseUrl, string $brandName): string
    {
        $base    = rtrim($baseUrl, '/');
        $prefix  = $lang === 'en' ? '' : '/' . $lang;
        $self     = $base . $prefix . '/news/feed.xml';
        $newsUrl  = $base . $prefix . '/news/';
        $title    = $brandName . ' — News';
        $desc     = $lang === 'de' ? 'Neueste Beiträge von ' . $brandName : 'Latest posts from ' . $brandName;
        $now      = blog_rfc822(gmdate('c'));

        // Escape text destined for element content (not CDATA).
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $cdata = static function (string $s): string {
            // Neutralize any stray "]]>" so a body can't break out of the CDATA.
            return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';
        };

        $items = '';
        foreach ($posts as $p) {
            if (!is_array($p) || empty($p['slug']) || empty($p['title'])) continue;
            $slug = (string) $p['slug'];
            $link = $base . $prefix . '/' . $slug . '/';
            $pub  = blog_rfc822((string) ($p['date'] ?? ''));
            $excerpt = blog_plain_text((string) ($p['excerpt'] ?? ''));
            $body    = (string) ($p['bodyHtml'] ?? '');

            $items .= "    <item>\n";
            $items .= '      <title>' . $x((string) $p['title']) . "</title>\n";
            $items .= '      <link>' . $x($link) . "</link>\n";
            $items .= '      <guid isPermaLink="true">' . $x($link) . "</guid>\n";
            if ($pub !== '') $items .= '      <pubDate>' . $x($pub) . "</pubDate>\n";
            if ($excerpt !== '') $items .= '      <description>' . $cdata($excerpt) . "</description>\n";
            if ($body !== '') $items .= '      <content:encoded>' . $cdata($body) . "</content:encoded>\n";
            $items .= "    </item>\n";
        }

        $rss  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rss .= '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" '
              . 'xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $rss .= "  <channel>\n";
        $rss .= '    <title>' . $x($title) . "</title>\n";
        $rss .= '    <link>' . $x($newsUrl) . "</link>\n";
        $rss .= '    <description>' . $x($desc) . "</description>\n";
        $rss .= '    <language>' . $x($lang) . "</language>\n";
        if ($now !== '') $rss .= '    <lastBuildDate>' . $x($now) . "</lastBuildDate>\n";
        $rss .= '    <atom:link href="' . $x($self) . '" rel="self" type="application/rss+xml"/>' . "\n";
        $rss .= $items;
        $rss .= "  </channel>\n";
        $rss .= "</rss>\n";

        return $rss;
    }
}

if (!function_exists('blog_image_tag')) {
    /**
     * Render a post image responsively. $img is the synced `image` descriptor:
     *   { src(jpg), webp?(full), alt?, width?, height?,
     *     derivatives?: { webp: [{u,w}, …], avif?: [{u,w}, …] } }
     *
     * With derivatives → a <picture> offering avif (optional) + webp <source>s
     * (srcset built from the derivative list) over a jpg <img>. Without them
     * (legacy posts) → a <picture> with a single webp <source> over the jpg, or a
     * bare <img> if there's no webp. Empty srcsets are never emitted. All
     * attributes are escaped via the Core h() escaper.
     *
     * $opts: sizes, loading('lazy'), fetchpriority, class, decoding('async').
     */
    function blog_image_tag(array $img, array $opts = []): string
    {
        $src = (string) ($img['src'] ?? '');
        if ($src === '') return '';

        $sizes    = (string) ($opts['sizes'] ?? '(max-width: 800px) 100vw, 800px');
        $loading  = (string) ($opts['loading'] ?? 'lazy');
        $decoding = (string) ($opts['decoding'] ?? 'async');
        $class    = isset($opts['class']) ? (string) $opts['class'] : '';
        $fetchpri = isset($opts['fetchpriority']) ? (string) $opts['fetchpriority'] : '';
        $alt      = (string) ($img['alt'] ?? '');
        $width    = isset($img['width'])  ? (string) $img['width']  : '';
        $height   = isset($img['height']) ? (string) $img['height'] : '';

        // <img> attribute string (shared by both branches).
        $imgAttrs  = 'src="' . h($src) . '"';
        if ($width  !== '') $imgAttrs .= ' width="' . h($width) . '"';
        if ($height !== '') $imgAttrs .= ' height="' . h($height) . '"';
        $imgAttrs .= ' alt="' . h($alt) . '"';
        if ($class !== '')    $imgAttrs .= ' class="' . h($class) . '"';
        if ($loading !== '')  $imgAttrs .= ' loading="' . h($loading) . '"';
        if ($decoding !== '') $imgAttrs .= ' decoding="' . h($decoding) . '"';
        if ($fetchpri !== '') $imgAttrs .= ' fetchpriority="' . h($fetchpri) . '"';
        $imgTag = '<img ' . $imgAttrs . '>';

        $derivs = (isset($img['derivatives']) && is_array($img['derivatives'])) ? $img['derivatives'] : [];

        // Build a "<u> <w>w, …" srcset from a derivative list; '' if none usable.
        $srcset = static function ($list): string {
            if (!is_array($list)) return '';
            $parts = [];
            foreach ($list as $d) {
                if (!is_array($d)) continue;
                $u = (string) ($d['u'] ?? '');
                if ($u === '') continue;
                $w = isset($d['w']) ? (int) $d['w'] : 0;
                $parts[] = $w > 0 ? h($u) . ' ' . $w . 'w' : h($u);
            }
            return implode(', ', $parts);
        };

        $avifSet = $srcset($derivs['avif'] ?? null);
        $webpSet = $srcset($derivs['webp'] ?? null);

        if ($avifSet !== '' || $webpSet !== '') {
            // Modern path: derivative-driven <picture>.
            $out = '<picture>';
            if ($avifSet !== '') {
                $out .= '<source type="image/avif" srcset="' . $avifSet . '" sizes="' . h($sizes) . '">';
            }
            if ($webpSet !== '') {
                $out .= '<source type="image/webp" srcset="' . $webpSet . '" sizes="' . h($sizes) . '">';
            }
            $out .= $imgTag . '</picture>';
            return $out;
        }

        // Legacy path: a single full-size webp <source> over the jpg, if present.
        $webpFull = (string) ($img['webp'] ?? '');
        if ($webpFull !== '') {
            return '<picture><source type="image/webp" srcset="' . h($webpFull) . '">' . $imgTag . '</picture>';
        }

        // No webp at all — a bare, escaped <img>.
        return $imgTag;
    }
}

if (!function_exists('blog_iso8601')) {
    /** Normalize a date string to ISO-8601 for sitemap <lastmod>; passthrough on failure. */
    function blog_iso8601(string $date): string
    {
        if ($date === '') return '';
        $ts = strtotime($date);
        return $ts === false ? $date : gmdate('c', $ts);
    }
}

if (!function_exists('blog_rfc822')) {
    /** RFC-822 date for RSS <pubDate>; '' when the input can't be parsed. */
    function blog_rfc822(string $date): string
    {
        if ($date === '') return '';
        $ts = strtotime($date);
        return $ts === false ? '' : gmdate('D, d M Y H:i:s O', $ts);
    }
}
