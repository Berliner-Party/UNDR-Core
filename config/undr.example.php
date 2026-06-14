<?php
declare(strict_types=1);

// UNDR sync configuration template. Copy to a site's config/undr.php and edit the
// values below — in practice only `brand` (and the legal/timezone) change per site.

return [
    'apiBase'      => 'https://undr.zone/api/v1',
    'brand'        => 'heat',                 // 'heat' | 'cage' | 'unleashed' | <new brand slug>
    'languages'    => ['en', 'de'],          // primary first; used for fallback
    'timezone'     => 'Europe/Berlin',

    'cacheDir'     => __DIR__ . '/../.cache/undr',   // outside public/ (web root), gitignored
    'assets'       => 'mirror',               // 'mirror' (download locally) | 'hotlink'
    'mediaDir'     => __DIR__ . '/../public/media',  // mirror target (gitignored), web-served
    'mediaBaseUrl' => '/media',               // public URL prefix for mirrored assets

    'httpTimeout'  => 8,                       // seconds per request
    'retries'      => 2,                       // extra attempts on network error / 5xx
    'maxAgeWarn'   => 21600,                    // advisory: warn if cache older than 6h

    'apiKey'       => getenv('UNDR_API_KEY') ?: null,  // public read API needs none
];
