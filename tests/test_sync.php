<?php
declare(strict_types=1);

// Plain-PHP regression tests for the sync engine's manifest ⇄ snapshot
// consistency (no framework, no network beyond 127.0.0.1).
//   php tests/test_sync.php
//
// Drives the real UndrSync + UndrHttp against a scripted API served by PHP's
// built-in web server (tests/fixtures/sync-api-router.php) and replays the
// publish race seen on cage.berlin 2026-09-15: the manifest already lists a
// freshly published event while /snapshot is still served from the not-yet-
// regenerated published cache. Expected: the old snapshot stays on disk, no
// fingerprint is recorded, the run is degraded, the /status timestamps do not
// advance — and the next tick with a consistent snapshot heals everything.

$root = dirname(__DIR__);
require $root . '/src/Http/UndrHttp.php';
require $root . '/src/Sync/SyncResult.php';
require $root . '/src/Sync/UndrSync.php';

use Undr\Core\Sync\UndrSync;

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . ($cond || $detail === '' ? '' : "  — $detail") . "\n";
    $cond ? $pass++ : $fail++;
}

// --- scripted API -----------------------------------------------------------
$work     = sys_get_temp_dir() . '/undr-sync-test-' . bin2hex(random_bytes(4));
$scenario = "$work/scenario.json";
$reqlog   = "$work/requests.log";
$cacheDir = "$work/cache";
@mkdir($work, 0775, true);
touch($reqlog);

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(stream_socket_get_name($sock, false), strrpos(stream_socket_get_name($sock, false), ':') + 1);
fclose($sock);

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/sync-api-router.php'],
    [1 => ['file', "$work/server.log", 'a'], 2 => ['file', "$work/server.log", 'a']],
    $pipes, null, ['SYNC_SCENARIO' => $scenario, 'SYNC_REQLOG' => $reqlog]
);
register_shutdown_function(static function () use ($server, $work): void {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    exec('rm -rf ' . escapeshellarg($work));
});
for ($i = 0; $i < 50; $i++) { // wait for the server to accept connections
    if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) break;
    usleep(100000);
}

// --- fixtures ----------------------------------------------------------------
$brand = 'cage';
$event = static fn(string $date, bool $published = true, string $rev = 'a'): array => [
    'id' => "$brand-$date", 'date' => $date, 'slug' => "cage-$date", 'status' => 'scheduled',
    'published' => $published, 'languages' => ['en'], 'updatedAt' => '2026-09-15T15:42:11+00:00',
    'hashes' => ['en' => 'sha256:' . md5("$date/$rev")], 'assets' => [],
];
$post = static fn(string $slug, bool $published = true): array => [
    'id' => "$brand-blog-$slug", 'slug' => $slug, 'date' => '2026-09-01', 'published' => $published,
    'languages' => ['en'], 'updatedAt' => '2026-09-01T00:00:00+00:00',
    'hashes' => ['en' => 'sha256:' . md5($slug)], 'assets' => [],
];
$manifest = static fn(array $events, array $posts, string $lm): array => [
    'brand' => $brand, 'generatedAt' => $lm, 'lastModified' => $lm, 'languages' => ['en'],
    'timezone' => 'Europe/Berlin', 'layers' => ['defaults' => ['hash' => 'sha256:d']],
    'events' => $events, 'posts' => $posts,
];
$snapEvent = static fn(string $date): array => ['id' => "$brand-$date", 'date' => $date, 'name' => "CAGE $date"];
$snapPost  = static fn(string $slug): array => ['id' => "$brand-blog-$slug", 'slug' => $slug, 'title' => $slug, 'bodyMarkdown' => 'hi'];

$setScenario = static function (array $s) use ($scenario): void {
    file_put_contents($scenario, json_encode($s, JSON_UNESCAPED_SLASHES));
};
$requests = static function () use ($reqlog): array {
    $lines = array_filter(explode("\n", (string) file_get_contents($reqlog)));
    file_put_contents($reqlog, '');
    return array_values($lines);
};
$state = static fn(): array => json_decode((string) @file_get_contents("$cacheDir/state.json"), true) ?: [];
$ids   = static function (string $file) use ($cacheDir): array {
    $d = json_decode((string) @file_get_contents("$cacheDir/$file"), true) ?: [];
    return array_map(static fn($e) => $e['id'], $d);
};
$run = static function () use ($port, $brand, $cacheDir) {
    return (new UndrSync([
        'apiBase' => "http://127.0.0.1:$port", 'brand' => $brand, 'languages' => ['en'],
        'cacheDir' => $cacheDir, 'assets' => 'hotlink', 'httpTimeout' => 3, 'retries' => 0,
    ]))->sync();
};

$lm1 = '2026-09-15T15:00:00+00:00'; $http1 = 'Tue, 15 Sep 2026 15:00:00 GMT';
$lm2 = '2026-09-15T15:42:11+00:00'; $http2 = 'Tue, 15 Sep 2026 15:42:11 GMT';
$lm3 = '2026-09-15T16:10:00+00:00'; $http3 = 'Tue, 15 Sep 2026 16:10:00 GMT';

// --- 1. clean first run: manifest and snapshot agree -------------------------
$setScenario([
    'statusLM' => $http1, 'brands' => [$brand => $lm1], 'manifestLM' => $http1,
    'manifest' => $manifest([$event('2026-09-12'), $event('2026-10-10')], [$post('premiere')], $lm1),
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $requests();
ok('1 first run: source=api, not degraded', $r->source === 'api' && !$r->degraded, $r->toLogLine());
ok('1 first run: snapshot has both events', $ids('events.en.json') === ["$brand-2026-09-12", "$brand-2026-10-10"]);
$fp1 = $state()['fingerprints']['en'] ?? null;
ok('1 first run: fingerprint recorded', is_string($fp1));
ok('1 first run: status timestamps recorded', $state()['statusLastModified'] === $http1 && $state()['brandLastModified'] === $lm1);

// --- 2. publish race, 304 flavour: manifest lists the new event, the published
//        cache still serves the OLD snapshot body → /snapshot 304s on our ETag.
$manifest2 = $manifest([$event('2026-09-12'), $event('2026-10-10'), $event('2026-11-14')], [$post('premiere')], $lm2);
$setScenario([
    'statusLM' => $http2, 'brands' => [$brand => $lm2], 'manifestLM' => $http2,
    'manifest' => $manifest2,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $requests();
ok('2 race/304: run degraded', $r->degraded, $r->toLogLine());
ok('2 race/304: lag error names the missing id', (bool) array_filter($r->errors, static fn($e) => $e['kind'] === 'lag' && str_contains($e['msg'], "$brand-2026-11-14")), $r->toLogLine());
ok('2 race/304: old snapshot kept on disk', $ids('events.en.json') === ["$brand-2026-09-12", "$brand-2026-10-10"]);
ok('2 race/304: fingerprint NOT advanced', ($state()['fingerprints']['en'] ?? null) === $fp1);
ok('2 race/304: statusLastModified untouched', $state()['statusLastModified'] === $http1, json_encode($state()['statusLastModified']));
ok('2 race/304: brandLastModified untouched', $state()['brandLastModified'] === $lm1, json_encode($state()['brandLastModified']));
ok('2 race/304: manifest cached for the retry', str_contains((string) file_get_contents("$cacheDir/manifest.json"), '2026-11-14'));

// --- 3. still racing, 200 flavour: a fresh (but still lagging) snapshot body ----
$setScenario([
    'statusLM' => $http2, 'brands' => [$brand => $lm2], 'manifestLM' => $http2,
    'manifest' => $manifest2, 'snapshotEtag' => false,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $reqs = $requests();
ok('3 race/200: retried — manifest 304 did not short-circuit the snapshot check', in_array("/brands/$brand/snapshot", $reqs, true), implode(' ', $reqs));
ok('3 race/200: run degraded, old snapshot kept', $r->degraded && $ids('events.en.json') === ["$brand-2026-09-12", "$brand-2026-10-10"], $r->toLogLine());
ok('3 race/200: fingerprint NOT advanced', ($state()['fingerprints']['en'] ?? null) === $fp1);
ok('3 race/200: status timestamps untouched', $state()['statusLastModified'] === $http1 && $state()['brandLastModified'] === $lm1);

// --- 4. published cache regenerated: same manifest (304), consistent snapshot ---
$setScenario([
    'statusLM' => $http2, 'brands' => [$brand => $lm2], 'manifestLM' => $http2,
    'manifest' => $manifest2,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10'), $snapEvent('2026-11-14')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $requests();
ok('4 healed: source=api, not degraded', $r->source === 'api' && !$r->degraded, $r->toLogLine());
ok('4 healed: snapshot now carries the new event', $ids('events.en.json') === ["$brand-2026-09-12", "$brand-2026-10-10", "$brand-2026-11-14"]);
$fp2 = $state()['fingerprints']['en'] ?? null;
ok('4 healed: fingerprint advanced', is_string($fp2) && $fp2 !== $fp1);
ok('4 healed: status timestamps advanced', $state()['statusLastModified'] === $http2 && $state()['brandLastModified'] === $lm2);

// --- 5. steady state: nothing changed → one /status request, 304 ----------------
$r = $run(); $reqs = $requests();
ok('5 steady: source=not-modified', $r->source === 'not-modified' && !$r->degraded, $r->toLogLine());
ok('5 steady: exactly one request (/status)', $reqs === ['/status'], implode(' ', $reqs));

// --- 6. snapshot fetch fails (500) after a brand change: timestamps must not move
$manifest3 = $manifest([$event('2026-09-12'), $event('2026-10-10'), $event('2026-11-14', true, 'b')], [$post('premiere')], $lm3);
$setScenario([
    'statusLM' => $http3, 'brands' => [$brand => $lm3], 'manifestLM' => $http3,
    'manifest' => $manifest3, 'snapshotStatus' => 500,
    'snapshot' => ['en' => ['events' => [], 'posts' => []]],
]);
$r = $run(); $requests();
ok('6 snapshot 500: degraded, old snapshot kept', $r->degraded && count($ids('events.en.json')) === 3, $r->toLogLine());
ok('6 snapshot 500: status timestamps untouched', $state()['statusLastModified'] === $http2 && $state()['brandLastModified'] === $lm2);
ok('6 snapshot 500: fingerprint NOT advanced', ($state()['fingerprints']['en'] ?? null) === $fp2);

$setScenario([
    'statusLM' => $http3, 'brands' => [$brand => $lm3], 'manifestLM' => $http3,
    'manifest' => $manifest3,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10'), $snapEvent('2026-11-14')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $reqs = $requests();
ok('6 recovery: next tick refetches the snapshot', in_array("/brands/$brand/snapshot", $reqs, true) && !$r->degraded, $r->toLogLine() . ' ' . implode(' ', $reqs));
ok('6 recovery: status timestamps advanced', $state()['statusLastModified'] === $http3 && $state()['brandLastModified'] === $lm3);
$fp3 = $state()['fingerprints']['en'] ?? null;

// --- 7. unpublish race: manifest drops the event, snapshot still carries it -----
$lm4 = '2026-09-15T17:00:00+00:00'; $http4 = 'Tue, 15 Sep 2026 17:00:00 GMT';
$manifest4 = $manifest([$event('2026-09-12'), $event('2026-10-10'), $event('2026-11-14', false, 'b')], [$post('premiere')], $lm4);
$setScenario([
    'statusLM' => $http4, 'brands' => [$brand => $lm4], 'manifestLM' => $http4,
    'manifest' => $manifest4,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10'), $snapEvent('2026-11-14')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $requests();
ok('7 unpublish race: degraded with lag error', $r->degraded && (bool) array_filter($r->errors, static fn($e) => $e['kind'] === 'lag' && str_contains($e['msg'], 'extra')), $r->toLogLine());
ok('7 unpublish race: fingerprint NOT advanced', ($state()['fingerprints']['en'] ?? null) === $fp3);

$setScenario([
    'statusLM' => $http4, 'brands' => [$brand => $lm4], 'manifestLM' => $http4,
    'manifest' => $manifest4,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10')], 'posts' => [$snapPost('premiere')]]],
]);
$r = $run(); $requests();
ok('7 unpublish healed: event gone from snapshot', !$r->degraded && $ids('events.en.json') === ["$brand-2026-09-12", "$brand-2026-10-10"], $r->toLogLine());

// --- 8. lost manifest cache on the 304 path → plain refetch, no error ----------
//        (brand timestamp moves so the run reaches the manifest; its body — and
//        therefore its ETag — is unchanged, so the API 304s.)
$lm5 = '2026-09-15T17:30:00+00:00'; $http5 = 'Tue, 15 Sep 2026 17:30:00 GMT';
$setScenario([
    'statusLM' => $http5, 'brands' => [$brand => $lm5], 'manifestLM' => $http4,
    'manifest' => $manifest4,
    'snapshot' => ['en' => ['events' => [$snapEvent('2026-09-12'), $snapEvent('2026-10-10')], 'posts' => [$snapPost('premiere')]]],
]);
unlink("$cacheDir/manifest.json");
$r = $run(); $reqs = $requests();
ok('8 lost manifest cache: refetched and rewritten', !$r->degraded && is_file("$cacheDir/manifest.json") && count(array_keys($reqs, "/brands/$brand/manifest", true)) === 2, $r->toLogLine() . ' ' . implode(' ', $reqs));

// --- 9. upgrade: state written by an older Core (schemaVersion 1) is not trusted
//        → one full pass re-verifies every language even though /status is quiet.
$st = $state(); $st['schemaVersion'] = 1;
$st['fingerprints']['en'] = 'recorded-by-old-core'; // old scheme ≠ new → snapshot re-verified
file_put_contents("$cacheDir/state.json", json_encode($st));
$r = $run(); $reqs = $requests();
ok('9 upgrade: full pass fetched manifest + snapshot', in_array("/brands/$brand/manifest", $reqs, true) && in_array("/brands/$brand/snapshot", $reqs, true) && !$r->degraded, $r->toLogLine() . ' ' . implode(' ', $reqs));
ok('9 upgrade: state now at current schema', ($state()['schemaVersion'] ?? null) === 2);
$r = $run(); $reqs = $requests();
ok('9 upgrade: next tick back to a single /status 304', $reqs === ['/status'] && $r->source === 'not-modified', implode(' ', $reqs));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
