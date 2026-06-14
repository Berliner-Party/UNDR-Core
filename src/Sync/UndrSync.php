<?php
declare(strict_types=1);

namespace Undr\Core\Sync;

use Undr\Core\Http\UndrHttp;

// ---------------------------------------------------------------------------
// UndrSync — pull pre-merged event data from the UNDR API into a local cache.
//
// Reusable, brand-agnostic, dependency-free. Driven entirely by a config array.
// Cron calls sync(); the website reads the cache snapshots written here.
//
//   GET {apiBase}/brands/{brand}/manifest                  → hashes per layer/event
//   GET {apiBase}/brands/{brand}/events?lang={L}&when=all  → array of merged events
//
// Smart caching: the manifest's per-layer + per-event hashes form a per-language
// fingerprint; a language's snapshot is only refetched when its fingerprint
// changes. Assets (flyer/promo) are mirrored locally and only re-downloaded when
// their content hash changes. Writes are atomic (tmp → rename) so a web request
// never observes a half-written snapshot. Any failure keeps the last-good cache.
// ---------------------------------------------------------------------------

final class UndrSync
{
    private string $apiBase;
    private string $brand;
    /** @var string[] */ private array $languages;
    private string $cacheDir;
    private string $tmpDir;
    private string $assetsMode;   // 'mirror' | 'hotlink'
    private string $mediaDir;
    private string $mediaBaseUrl;
    private int $maxAgeWarn;
    private UndrHttp $http;

    public function __construct(array $config)
    {
        $this->apiBase      = rtrim($config['apiBase'] ?? 'https://undr.zone/api/v1', '/');
        $this->brand        = $config['brand'] ?? 'heat';
        $this->languages    = $config['languages'] ?? ['en'];
        $this->cacheDir     = rtrim($config['cacheDir'] ?? (__DIR__ . '/../.cache/undr'), '/');
        $this->tmpDir       = $this->cacheDir . '/.tmp';
        $this->assetsMode   = $config['assets'] ?? 'mirror';
        $this->mediaDir     = rtrim($config['mediaDir'] ?? (__DIR__ . '/../public/media'), '/');
        $this->mediaBaseUrl = rtrim($config['mediaBaseUrl'] ?? '/media', '/');
        $this->maxAgeWarn   = (int) ($config['maxAgeWarn'] ?? 21600);

        $headers = [];
        if (!empty($config['apiKey'])) $headers[] = 'Authorization: Bearer ' . $config['apiKey'];
        $this->http = new UndrHttp(
            (int) ($config['httpTimeout'] ?? 8),
            (int) ($config['retries'] ?? 2),
            $headers
        );
    }

    // -----------------------------------------------------------------------
    public function sync(): SyncResult
    {
        $r = new SyncResult();
        $r->startedAt = gmdate('c');
        $t0 = hrtime(true);

        @mkdir($this->cacheDir, 0775, true);
        @mkdir($this->tmpDir, 0775, true);

        // Single-run lock; overlapping cron is a no-op (not a failure).
        $lock = @fopen($this->cacheDir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $r->skipped = true;
            $r->source  = 'cache';
            $this->finish($r, $t0);
            return $r;
        }

        try {
            $this->run($r);
        } catch (\Throwable $e) {
            $r->addError('fatal', $e->getMessage());
            $r->exitCode = 1;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->finish($r, $t0);
        return $r;
    }

    private function finish(SyncResult $r, int $t0): void
    {
        $r->durationMs = (hrtime(true) - $t0) / 1e6;
    }

    // -----------------------------------------------------------------------
    private function run(SyncResult $r): void
    {
        $state  = $this->loadState();
        $primed = $this->haveAllSnapshots(); // only short-circuit when the cache is complete

        // 0) Cheapest precheck — the global /status endpoint (one tiny request, safe to
        //    poll every minute). A conditional GET 304s when nothing changed anywhere:
        //    no manifest, no events. If it 200s, compare THIS brand's timestamp and skip
        //    the manifest when only *other* brands moved.
        $statusLM = $state['statusLastModified'] ?? null;
        $brandLM  = $state['brandLastModified'] ?? null;

        $st = $this->http->get($this->statusUrl(), $primed ? ['lastModified' => $statusLM] : []);
        if ($primed && $st->notModified()) {
            $r->source = 'not-modified';
            $this->warnIfStale($state, $r);
            return;
        }
        if ($st->ok()) {
            $s = json_decode($st->body, true);
            if (is_array($s)) {
                $statusLM   = $st->lastModified ?? $statusLM;
                $newBrandLM = $s['brands'][$this->brand] ?? null;
                if ($primed && $newBrandLM !== null && $newBrandLM === $brandLM) {
                    $this->persistStatus($state, $statusLM, $brandLM); // only other brands moved
                    $r->source = 'not-modified';
                    return;
                }
                if ($newBrandLM !== null) $brandLM = $newBrandLM;
            }
        }
        // status unreachable/!ok → fall through; the manifest path is the source of truth.

        // 1) Manifest — conditional (the API now 304s on If-Modified-Since).
        $res = $this->http->get($this->manifestUrl(), $primed ? [
            'etag'         => $state['manifestEtag'] ?? null,
            'lastModified' => $state['manifestLastModified'] ?? null,
        ] : []);

        if ($primed && $res->notModified()) {
            $this->persistStatus($state, $statusLM, $brandLM);
            $r->source = 'not-modified';
            $this->warnIfStale($state, $r);
            return; // nothing changed server-side
        }
        if (!$res->ok()) {
            $r->degraded = true;
            $r->source   = $this->haveAnySnapshot() ? 'cache' : 'none';
            $r->addError($res->transportError() ? 'network' : 'http', 'manifest status=' . $res->status);
            return; // keep last-good cache
        }

        $manifest = json_decode($res->body, true);
        if (!$this->validManifest($manifest)) {
            $r->degraded = true;
            $r->source   = $this->haveAnySnapshot() ? 'cache' : 'none';
            $r->addError('schema', 'manifest shape invalid');
            return;
        }

        // 2) Mirror assets (content-hash diffed). Map: absolute UNDR url → local /media url.
        $assetMap = [];
        $assetState = $state['assets'] ?? [];
        if ($this->assetsMode === 'mirror') {
            [$assetMap, $assetState, $mirrored] = $this->mirrorAssets($manifest, $assetState, $r);
            $r->assetsMirrored = $mirrored;
        }

        // 3) Per-language fingerprint diff → fetch only changed languages.
        $updatedAt   = $this->updatedAtById($manifest);
        $fingerprints = $state['fingerprints'] ?? [];
        $newFingerprints = $fingerprints;
        $langEtags = $state['langEtags'] ?? [];
        $anyWritten = false;

        foreach ($this->languages as $lang) {
            $fp = $this->fingerprint($manifest, $lang);
            $haveSnapshot = is_file($this->snapshotPath($lang));
            if ($haveSnapshot && ($fingerprints[$lang] ?? null) === $fp) {
                continue; // unchanged
            }

            $evRes = $this->http->get($this->eventsUrl($lang), ['etag' => $langEtags[$lang] ?? null]);
            if ($evRes->notModified()) { $newFingerprints[$lang] = $fp; continue; }
            if (!$evRes->ok()) {
                $r->degraded = true;
                $r->addError($evRes->transportError() ? 'network' : 'http', "events[$lang] status=" . $evRes->status);
                continue; // keep this lang's old snapshot
            }
            $events = json_decode($evRes->body, true);
            if (!$this->validEvents($events)) {
                $r->degraded = true;
                $r->addError('schema', "events[$lang] shape invalid");
                continue;
            }

            $events = $this->injectUpdatedAt($events, $updatedAt);
            if ($this->assetsMode === 'mirror' && $assetMap) {
                $events = $this->rewriteAssetUrls($events, $assetMap);
            }

            $this->writeJsonAtomic($this->snapshotPath($lang), $events);
            $newFingerprints[$lang] = $fp;
            if ($evRes->etag) $langEtags[$lang] = $evRes->etag;
            $r->changedLangs[] = $lang;
            $r->eventsWritten += count($events);
            $anyWritten = true;
        }

        // 4) Persist manifest + state (snapshots already durably written above).
        $this->writeRawAtomic($this->cacheDir . '/manifest.json', $res->body);
        $this->writeJsonAtomic($this->cacheDir . '/state.json', [
            'schemaVersion'        => 1,
            'lastSync'             => gmdate('c'),
            'generatedAt'          => $manifest['generatedAt'] ?? null,
            'lastModified'         => $manifest['lastModified'] ?? null,
            'manifestEtag'         => $res->etag,
            'manifestLastModified' => $res->lastModified,
            'statusLastModified'   => $statusLM ?? ($state['statusLastModified'] ?? null),
            'brandLastModified'    => $brandLM ?? ($state['brandLastModified'] ?? null),
            'fingerprints'         => $newFingerprints,
            'langEtags'            => $langEtags,
            'assets'               => $assetState,
        ]);

        $r->source = $anyWritten ? 'api' : ($r->degraded ? ($this->haveAnySnapshot() ? 'cache' : 'none') : 'not-modified');
    }

    /** Persist only the change-tracking timestamps (used on the skip paths). */
    private function persistStatus(array $state, ?string $statusLM, ?string $brandLM): void
    {
        $state['statusLastModified'] = $statusLM ?? ($state['statusLastModified'] ?? null);
        $state['brandLastModified']  = $brandLM ?? ($state['brandLastModified'] ?? null);
        $state['lastSync']           = gmdate('c');
        $this->writeJsonAtomic($this->cacheDir . '/state.json', $state);
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------
    /** @return array{0: array<string,string>, 1: array, 2: int} [urlMap, newAssetState, mirroredCount] */
    private function mirrorAssets(array $manifest, array $assetState, SyncResult $r): array
    {
        $map = [];
        $mirrored = 0;
        foreach ($manifest['events'] ?? [] as $ev) {
            $date = $ev['date'] ?? null;
            foreach ($ev['assets'] ?? [] as $a) {
                $src  = $a['src'] ?? null;
                $hash = $a['hash'] ?? null;
                if (!$src || !$hash || !$date) continue;

                $local = $this->localAssetPath($date, $src, $hash);
                $diskPath = $this->mediaDir . $this->localToFsSuffix($local);

                if (($assetState[$src]['hash'] ?? null) === $hash && is_file($diskPath)) {
                    $map[$src] = $local; // already mirrored, unchanged
                    continue;
                }

                if ($this->downloadTo($src, $diskPath)) {
                    $map[$src] = $local;
                    $assetState[$src] = ['hash' => $hash, 'local' => $local];
                    $mirrored++;
                } else {
                    $r->addError('asset', 'mirror failed: ' . $src); // event keeps absolute URL (hotlink fallback)
                }
            }
        }
        return [$map, $assetState, $mirrored];
    }

    /** Public /media URL for a mirrored asset, hash-prefixed for cache-busting. */
    private function localAssetPath(string $date, string $src, string $hash): string
    {
        $base = basename(parse_url($src, PHP_URL_PATH) ?: $src);
        $sha8 = substr(preg_replace('~^sha256:~', '', $hash), 0, 8);
        return $this->mediaBaseUrl . '/' . rawurlencode($this->brand) . '/' . $date . '/' . $sha8 . '.' . $base;
    }

    /** Map a public /media URL back to its filesystem suffix under mediaDir. */
    private function localToFsSuffix(string $localUrl): string
    {
        return substr($localUrl, strlen($this->mediaBaseUrl)); // '/heat/<date>/<sha8>.<name>'
    }

    private function downloadTo(string $url, string $fsPath): bool
    {
        $res = $this->http->get($url);
        if (!$res->ok() || $res->body === '') return false;
        @mkdir(dirname($fsPath), 0775, true);
        $tmp = $fsPath . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (@file_put_contents($tmp, $res->body) === false) { @unlink($tmp); return false; }
        if (!@rename($tmp, $fsPath)) { @unlink($tmp); return false; }
        return true;
    }

    /** Rewrite flyer/promo absolute UNDR URLs to local /media URLs where mirrored. */
    private function rewriteAssetUrls(array $events, array $map): array
    {
        foreach ($events as &$e) {
            foreach (['flyer' => ['src', 'webp'], 'promo' => ['src', 'poster']] as $obj => $fields) {
                if (!isset($e[$obj]) || !is_array($e[$obj])) continue;
                foreach ($fields as $f) {
                    if (!empty($e[$obj][$f]) && isset($map[$e[$obj][$f]])) {
                        $e[$obj][$f] = $map[$e[$obj][$f]];
                    }
                }
            }
        }
        unset($e);
        return $events;
    }

    // -----------------------------------------------------------------------
    // Manifest helpers
    // -----------------------------------------------------------------------
    private function fingerprint(array $manifest, string $lang): string
    {
        $parts = [
            'defaults:'      . ($manifest['layers']['defaults']['hash'] ?? ''),
            'strings.' . $lang . ':' . ($manifest['layers']['strings.' . $lang]['hash'] ?? ''),
        ];
        $events = $manifest['events'] ?? [];
        usort($events, fn($a, $b) => strcmp($a['id'] ?? '', $b['id'] ?? ''));
        foreach ($events as $ev) {
            $parts[] = ($ev['id'] ?? '') . '=' . ($ev['hashes'][$lang] ?? '');
        }
        return hash('sha256', implode('|', $parts));
    }

    private function updatedAtById(array $manifest): array
    {
        $map = [];
        foreach ($manifest['events'] ?? [] as $ev) {
            if (!empty($ev['id']) && !empty($ev['updatedAt'])) $map[$ev['id']] = $ev['updatedAt'];
        }
        return $map;
    }

    private function injectUpdatedAt(array $events, array $updatedAt): array
    {
        foreach ($events as &$e) {
            if (!empty($e['id']) && isset($updatedAt[$e['id']]) && empty($e['updatedAt'])) {
                $e['updatedAt'] = $updatedAt[$e['id']];
            }
        }
        unset($e);
        return $events;
    }

    // -----------------------------------------------------------------------
    // Validation (cheap, no JSON-Schema lib) — the firewall against bad data.
    // -----------------------------------------------------------------------
    private function validManifest($m): bool
    {
        return is_array($m)
            && !empty($m['brand'])
            && isset($m['events']) && is_array($m['events'])
            && isset($m['languages']) && is_array($m['languages']);
    }

    private function validEvents($events): bool
    {
        if (!is_array($events) || ($events !== [] && !array_is_list($events))) return false;
        foreach ($events as $e) {
            if (!is_array($e) || empty($e['id']) || empty($e['date']) || empty($e['name'])) return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // State + atomic IO
    // -----------------------------------------------------------------------
    private function loadState(): array
    {
        $raw = @file_get_contents($this->cacheDir . '/state.json');
        if ($raw === false) return [];
        $s = json_decode($raw, true);
        return is_array($s) ? $s : [];
    }

    private function writeJsonAtomic(string $path, $data): void
    {
        $this->writeRawAtomic($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function writeRawAtomic(string $path, string $contents): void
    {
        @mkdir($this->tmpDir, 0775, true);
        $tmp = $this->tmpDir . '/' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException('cannot write tmp for ' . $path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot rename into ' . $path);
        }
    }

    private function warnIfStale(array $state, SyncResult $r): void
    {
        if (empty($state['lastSync'])) return;
        if ((time() - strtotime($state['lastSync'])) > $this->maxAgeWarn) {
            $r->addError('stale', 'lastSync=' . $state['lastSync']);
        }
    }

    private function haveAnySnapshot(): bool
    {
        foreach ($this->languages as $lang) {
            if (is_file($this->snapshotPath($lang))) return true;
        }
        return false;
    }

    private function haveAllSnapshots(): bool
    {
        if ($this->languages === []) return false;
        foreach ($this->languages as $lang) {
            if (!is_file($this->snapshotPath($lang))) return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    private function statusUrl(): string { return $this->apiBase . '/status'; }
    private function manifestUrl(): string { return $this->apiBase . '/brands/' . rawurlencode($this->brand) . '/manifest'; }
    private function eventsUrl(string $lang): string
    {
        return $this->apiBase . '/brands/' . rawurlencode($this->brand) . '/events?lang=' . rawurlencode($lang) . '&when=all';
    }
    private function snapshotPath(string $lang): string { return $this->cacheDir . '/events.' . $lang . '.json'; }
}
