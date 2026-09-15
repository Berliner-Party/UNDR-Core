<?php
declare(strict_types=1);

// Scripted stand-in for the UNDR API, run under `php -S` by tests/test_sync.php.
// Every response is derived from the scenario JSON file named by SYNC_SCENARIO
// (rewritten by the test between sync runs); every request path is appended to
// SYNC_REQLOG so the test can assert what the sync fetched.
//
//   { "statusLM": "<HTTP date>", "brands": {"cage": "<iso>"},
//     "manifest": {...}, "snapshot": {"en": {"events": [...], "posts": [...]}},
//     "snapshotStatus": 200, "snapshotEtag": true }

$scenario = json_decode((string) file_get_contents((string) getenv('SYNC_SCENARIO')), true) ?: [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?: '', $q);
file_put_contents((string) getenv('SYNC_REQLOG'), $path . "\n", FILE_APPEND);

$emit = static function (array $data, ?string $etag = null, ?string $lastModified = null, int $status = 200): never {
    $body = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($etag !== null) {
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) { http_response_code(304); exit; }
    }
    if ($lastModified !== null) {
        header('Last-Modified: ' . $lastModified);
        if (($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null) === $lastModified) { http_response_code(304); exit; }
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo $body;
    exit;
};

if ($path === '/status') {
    $emit(['brands' => $scenario['brands'] ?? []], null, $scenario['statusLM'] ?? null);
}
if ($path === '/brands') {
    $emit([]);
}
if (preg_match('~^/brands/[^/]+/manifest$~', $path)) {
    $m = $scenario['manifest'] ?? [];
    $emit($m, 'W/"' . md5(json_encode($m)) . '"', $scenario['manifestLM'] ?? null);
}
if (preg_match('~^/brands/[^/]+/snapshot$~', $path)) {
    $status = (int) ($scenario['snapshotStatus'] ?? 200);
    if ($status !== 200) { http_response_code($status); exit; }
    $s = $scenario['snapshot'][$q['lang'] ?? 'en'] ?? ['events' => [], 'posts' => []];
    $etag = ($scenario['snapshotEtag'] ?? true) ? 'W/"' . md5(json_encode($s)) . '"' : null;
    $emit($s, $etag); // like the real API: no Last-Modified on /snapshot
}
http_response_code(404);
echo '{"error":"not found"}';
