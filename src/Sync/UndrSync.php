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
//   GET {apiBase}/brands/{brand}/manifest          → hashes per layer/event/post
//   GET {apiBase}/brands/{brand}/snapshot?lang={L} → { events:[…], posts:[…] }
//
// Events and blog posts ride the SAME per-language /snapshot request (one fetch,
// one fingerprint) and are split into events.{L}.json + blog.{L}.json locally.
//
// Smart caching: the manifest's per-layer + per-event/post hashes form a
// per-language fingerprint; a language's snapshot is only refetched when its
// changes. Assets (flyer/promo) are mirrored locally and only re-downloaded when
// their content hash changes. Writes are atomic (tmp → rename) so a web request
// never observes a half-written snapshot. Any failure keeps the last-good cache.
//
// Consistency: a fingerprint is only recorded once the fetched snapshot carries
// exactly the published ids the manifest lists (the API regenerates its
// published cache a few seconds after the manifest moves — see snapshotLag()).
// A degraded run leaves the /status timestamps untouched so the next tick
// retries instead of 304-ing past the gap.
// ---------------------------------------------------------------------------

final class UndrSync
{
    /** Bump when state.json semantics change; older state forces one full pass. */
    private const STATE_SCHEMA = 2;

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
        // Only short-circuit when the cache is complete AND was written by this
        // state schema: older state recorded fingerprints without the manifest ⇄
        // snapshot check (and with a fingerprint blind to unpublishing), so the
        // first run after upgrading Core does one full, verified pass.
        $primed = $this->haveAllSnapshots() && ($state['schemaVersion'] ?? 0) === self::STATE_SCHEMA;

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
        $statusBrands = [];
        if ($st->ok()) {
            $s = json_decode($st->body, true);
            if (is_array($s)) {
                $statusLM     = $st->lastModified ?? $statusLM;
                $statusBrands = is_array($s['brands'] ?? null) ? $s['brands'] : [];
                $newBrandLM   = $statusBrands[$this->brand] ?? null;
                if ($primed && $newBrandLM !== null && $newBrandLM === $brandLM) {
                    // Only other brands moved — but one of them may be an event
                    // a blog post links to; refresh the linked-events cache then.
                    if ($this->linkedNeedsSync($state, $statusBrands)) {
                        $state['linked'] = $this->syncLinkedEvents($state, $r, $statusBrands);
                    }
                    $this->persistStatus($state, $statusLM, $brandLM);
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

        // A 304 proves the manifest we hold is current — NOT that the snapshots
        // are. A previous run may have ended degraded (snapshot fetch failed, or
        // the snapshot lagged the manifest after a publish race), in which case
        // the missing content must be retried now, with the same manifest. So
        // never return here: re-verify every language against the cached
        // manifest below. A consistent language costs nothing (its fingerprint
        // matches → no request), so the steady state is still a single 304.
        $manifest = null;
        if ($primed && $res->notModified()) {
            $manifest = json_decode((string) $this->readLocalRaw($this->cacheDir . '/manifest.json'), true);
            if (!$this->validManifest($manifest)) { // cache lost/corrupt → plain refetch
                $manifest = null;
                $res = $this->http->get($this->manifestUrl());
            }
        }
        $manifestFresh = $manifest === null; // fetched this run (vs. reused from cache)
        if ($manifestFresh) {
            if (!$res->ok()) {
                $r->degraded = true;
                $r->source   = $this->haveAnySnapshot() ? 'cache' : 'none';
                $r->addError($res->transportError() ? 'network' : 'http', 'manifest status=' . $res->status);
                return; // keep last-good cache
            }
            $manifest = json_decode($res->body, true);
        }
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

        // 3) Per-language fingerprint diff → fetch only changed languages. Events
        //    AND blog posts ride ONE /snapshot request per language (one fetch,
        //    one fingerprint), then split into their respective local snapshots —
        //    the blog is no longer a separate API round-trip.
        $updatedAt   = $this->updatedAtById($manifest);
        $fingerprints = $state['fingerprints'] ?? [];
        $newFingerprints = $fingerprints;
        $langEtags = $state['langEtags'] ?? [];
        $anyWritten = false;

        foreach ($this->languages as $lang) {
            $fp = $this->contentFingerprint($manifest, $lang);
            $haveSnapshot = is_file($this->snapshotPath($lang)) && is_file($this->blogSnapshotPath($lang));
            if ($haveSnapshot && ($fingerprints[$lang] ?? null) === $fp) {
                continue; // unchanged
            }

            $snRes = $this->http->get($this->snapshotUrl($lang), ['etag' => $langEtags[$lang] ?? null]);
            if ($snRes->notModified()) {
                // The snapshot on disk is byte-identical to what the API serves —
                // but a 304 right after a publish can mean the API's published
                // cache simply hasn't caught up with the manifest yet.
                $lag = $haveSnapshot ? $this->snapshotLag(
                    $manifest,
                    $this->readLocalJson($this->snapshotPath($lang)) ?? [],
                    $this->readLocalJson($this->blogSnapshotPath($lang)) ?? []
                ) : 'snapshot missing';
                if ($lag !== null) {
                    $r->degraded = true;
                    $r->addError('lag', "snapshot[$lang] lags manifest: $lag");
                    unset($langEtags[$lang]); // next tick fetches unconditionally
                    continue;                 // fingerprint stays unrecorded → retried
                }
                $newFingerprints[$lang] = $fp;
                continue;
            }
            if (!$snRes->ok()) {
                $r->degraded = true;
                $r->addError($snRes->transportError() ? 'network' : 'http', "snapshot[$lang] status=" . $snRes->status);
                continue; // keep this lang's old snapshots
            }
            $snap = json_decode($snRes->body, true);
            if (!is_array($snap) || !$this->validEvents($snap['events'] ?? null) || !$this->validBlogPosts($snap['posts'] ?? null)) {
                $r->degraded = true;
                $r->addError('schema', "snapshot[$lang] shape invalid");
                continue;
            }
            // Publish race: the manifest (built from the store) can list content
            // the snapshot (served from the published cache, regenerated a few
            // seconds later) does not carry yet. Recording the manifest
            // fingerprint for such a snapshot would freeze this language until
            // something else about the brand changes — keep the previous
            // snapshot, record nothing, and let the next tick retry.
            $lag = $this->snapshotLag($manifest, $snap['events'], $snap['posts']);
            if ($lag !== null) {
                $r->degraded = true;
                $r->addError('lag', "snapshot[$lang] lags manifest: $lag");
                continue;
            }

            $events = $this->injectUpdatedAt($snap['events'], $updatedAt);
            $posts  = $snap['posts'];
            if ($this->assetsMode === 'mirror' && $assetMap) {
                $events = $this->rewriteAssetUrls($events, $assetMap);
                $posts  = $this->rewriteBlogAssetUrls($posts, $assetMap);
            }

            $this->writeJsonAtomic($this->snapshotPath($lang), $events);
            $this->writeJsonAtomic($this->blogSnapshotPath($lang), $posts);
            $newFingerprints[$lang] = $fp;
            if ($snRes->etag) $langEtags[$lang] = $snRes->etag;
            $r->changedLangs[] = $lang;
            $r->eventsWritten += count($events);
            $r->postsWritten  += count($posts);
            $anyWritten = true;
        }

        // 3b) Cross-brand [event:…] refs in the synced posts → linked-events
        //     cache (+ the brand registry the cards link through).
        $linkedState = $this->syncLinkedEvents($state, $r, $statusBrands);

        // 4) Persist manifest + state (snapshots already durably written above).
        //    The /status timestamps advance only when every language ended this
        //    run consistent: a degraded run must not let the next tick 304 on
        //    /status and skip the retry of whatever is still missing.
        if ($manifestFresh) {
            $this->writeRawAtomic($this->cacheDir . '/manifest.json', $res->body);
        }
        $this->writeJsonAtomic($this->cacheDir . '/state.json', [
            'schemaVersion'        => self::STATE_SCHEMA,
            'lastSync'             => gmdate('c'),
            'generatedAt'          => $manifest['generatedAt'] ?? null,
            'lastModified'         => $manifest['lastModified'] ?? null,
            'manifestEtag'         => $manifestFresh ? $res->etag : ($state['manifestEtag'] ?? null),
            'manifestLastModified' => $manifestFresh ? $res->lastModified : ($state['manifestLastModified'] ?? null),
            'statusLastModified'   => $r->degraded ? ($state['statusLastModified'] ?? null) : ($statusLM ?? ($state['statusLastModified'] ?? null)),
            'brandLastModified'    => $r->degraded ? ($state['brandLastModified'] ?? null) : ($brandLM ?? ($state['brandLastModified'] ?? null)),
            'fingerprints'         => $newFingerprints,
            'langEtags'            => $langEtags,
            'assets'               => $assetState,
            'linked'               => $linkedState,
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
        // Blog post images, keyed by slug under /media/<brand>/blog/<slug>/.
        // Only mirror the images of PUBLISHED posts — the ones that actually sync
        // into blog.<lang>.json. A draft's image is never rendered, so don't copy it.
        foreach ($manifest['posts'] ?? [] as $p) {
            $slug = $p['slug'] ?? null;
            if (empty($p['published'])) {
                continue;
            }
            foreach ($p['assets'] ?? [] as $a) {
                $src  = $a['src'] ?? null;
                $hash = $a['hash'] ?? null;
                if (!$src || !$hash || !$slug) continue;

                $local = $this->localBlogAssetPath($slug, $src, $hash);
                $diskPath = $this->mediaDir . $this->localToFsSuffix($local);

                if (($assetState[$src]['hash'] ?? null) === $hash && is_file($diskPath)) {
                    $map[$src] = $local;
                    continue;
                }
                if ($this->downloadTo($src, $diskPath)) {
                    $map[$src] = $local;
                    $assetState[$src] = ['hash' => $hash, 'local' => $local];
                    $mirrored++;
                } else {
                    $r->addError('asset', 'mirror failed: ' . $src);
                }
            }
        }
        return [$map, $assetState, $mirrored];
    }

    /** Public /media URL for a mirrored post image, hash-prefixed for cache-busting. */
    private function localBlogAssetPath(string $slug, string $src, string $hash): string
    {
        $base = basename(parse_url($src, PHP_URL_PATH) ?: $src);
        $sha8 = substr(preg_replace('~^sha256:~', '', $hash), 0, 8);
        return $this->mediaBaseUrl . '/' . rawurlencode($this->brand) . '/blog/' . $slug . '/' . $sha8 . '.' . $base;
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

    /** Rewrite post image absolute UNDR URLs to local /media URLs where mirrored. */
    private function rewriteBlogAssetUrls(array $posts, array $map): array
    {
        foreach ($posts as &$p) {
            if (isset($p['image']) && is_array($p['image'])) {
                foreach (['src', 'webp'] as $f) {
                    if (!empty($p['image'][$f]) && isset($map[$p['image'][$f]])) {
                        $p['image'][$f] = $map[$p['image'][$f]];
                    }
                }
                // Responsive derivative tiers: rewrite each tier's `u` URL through
                // the same map so mirrored local /media paths are served.
                if (isset($p['image']['derivatives']) && is_array($p['image']['derivatives'])) {
                    foreach (['webp', 'avif'] as $fmt) {
                        if (!isset($p['image']['derivatives'][$fmt]) || !is_array($p['image']['derivatives'][$fmt])) {
                            continue;
                        }
                        foreach ($p['image']['derivatives'][$fmt] as &$tier) {
                            if (is_array($tier) && !empty($tier['u']) && isset($map[$tier['u']])) {
                                $tier['u'] = $map[$tier['u']];
                            }
                        }
                        unset($tier);
                    }
                }
            }
        }
        unset($p);
        return $posts;
    }

    // -----------------------------------------------------------------------
    // Linked events — cross-brand [event:…] blog shortcuts
    // -----------------------------------------------------------------------
    // Blog posts may embed [event:<brand>-<date>] shortcuts pointing at ANOTHER
    // brand's event (a CAGE card inside an UNLEASHED post). Those events are not
    // in this brand's snapshot, so the sync resolves them here: scan the synced
    // posts for foreign refs, mirror the brand registry (GET /brands →
    // brands.json) and each referenced event (GET /brands/<b>/events/<date>
    // → linked-events.<lang>.json, id-keyed). Etag-cached per event+lang,
    // atomic writes, last-good kept on failure — same rules as the snapshots.
    // Flyers of linked events keep their absolute UNDR media URLs (hotlink).

    /**
     * Cheap dirty-check for the "own brand unchanged" fast path: true when a
     * referenced foreign brand moved (per /status lastModified) or a linked
     * cache file is missing while refs exist.
     */
    private function linkedNeedsSync(array $state, array $statusBrands): bool
    {
        $linked = $state['linked'] ?? null;
        if (!is_array($linked)) return true; // pass never ran
        $brands = is_array($linked['brands'] ?? null) ? $linked['brands'] : [];
        if ($brands === []) return false;    // no foreign refs recorded
        foreach ($this->languages as $lang) {
            if (!is_file($this->linkedPath($lang))) return true;
        }
        foreach ($brands as $b) {
            $lm = $statusBrands[$b] ?? null;
            if ($lm !== null && $lm !== ($linked['brandLM'][$b] ?? null)) return true;
        }
        return false;
    }

    /** Sync brands.json + linked-events.<lang>.json; returns the new state slice. */
    private function syncLinkedEvents(array $state, SyncResult $r, array $statusBrands): array
    {
        $prev  = is_array($state['linked'] ?? null) ? $state['linked'] : [];
        $etags = is_array($prev['etags'] ?? null) ? $prev['etags'] : [];
        $refs  = $this->collectLinkedRefs();

        $linked = [
            'brands'     => array_keys($refs),
            'brandLM'    => [],
            'etags'      => [],
            'brandsEtag' => $prev['brandsEtag'] ?? null,
        ];

        // Brand registry — the cards need the foreign site's URL/name/languages.
        // Only fetched while posts actually reference a foreign event.
        if ($refs !== []) {
            $bRes = $this->http->get($this->apiBase . '/brands', ['etag' => $linked['brandsEtag']]);
            if ($bRes->ok() && is_array(json_decode($bRes->body, true))) {
                $this->writeRawAtomic($this->cacheDir . '/brands.json', $bRes->body);
                $linked['brandsEtag'] = $bRes->etag;
            } elseif (!$bRes->notModified()) {
                $r->addError('linked', 'brands status=' . $bRes->status); // keep last-good brands.json
            }
        }
        $registry = [];
        foreach ((array) ($this->readLocalJson($this->cacheDir . '/brands.json') ?? []) as $b) {
            if (is_array($b) && !empty($b['slug'])) $registry[$b['slug']] = $b;
        }

        foreach ($this->languages as $lang) {
            $old = $this->readLocalJson($this->linkedPath($lang)) ?? [];
            $out = [];
            foreach ($refs as $brand => $dates) {
                $meta = $registry[$brand] ?? [];
                foreach ($dates as $date) {
                    $id   = $brand . '-' . $date;
                    $key  = $lang . '|' . $id;
                    $cond = isset($old[$id], $etags[$key]) ? ['etag' => $etags[$key]] : [];
                    $res  = $this->http->get($this->linkedEventUrl($brand, $date, $lang), $cond);

                    if ($res->notModified()) {
                        $out[$id] = $old[$id];
                        $linked['etags'][$key] = $etags[$key];
                        continue;
                    }
                    if ($res->status === 404) continue; // unpublished/deleted → drop
                    $e = $res->ok() ? json_decode($res->body, true) : null;
                    if (!is_array($e) || empty($e['id']) || empty($e['date']) || empty($e['name'])) {
                        if (isset($old[$id])) $out[$id] = $old[$id]; // keep last-good
                        $r->addError('linked', "event[$id,$lang] status=" . $res->status);
                        continue;
                    }
                    $e['_brand'] = $brand;
                    if (!empty($meta['name']))      $e['_brandName'] = $meta['name'];
                    if (!empty($meta['website']))   $e['_website']   = $meta['website'];
                    if (!empty($meta['languages'])) $e['_languages'] = $meta['languages'];
                    $out[$id] = $e;
                    if ($res->etag) $linked['etags'][$key] = $res->etag;
                    $r->linkedWritten++;
                }
            }
            $this->writeJsonAtomic($this->linkedPath($lang), (object) $out); // id-keyed map, {} when empty
        }

        foreach (array_keys($refs) as $b) { // remember each brand's LM for the dirty check
            $linked['brandLM'][$b] = $statusBrands[$b] ?? ($prev['brandLM'][$b] ?? null);
        }
        return $linked;
    }

    /**
     * Scan the synced blog snapshots for [event:…] refs to OTHER brands.
     * @return array<string,string[]> brand slug → list of dates
     */
    private function collectLinkedRefs(): array
    {
        $refs = [];
        foreach ($this->languages as $lang) {
            $posts = $this->readLocalJson($this->blogSnapshotPath($lang));
            foreach (is_array($posts) ? $posts : [] as $p) {
                if (!is_array($p)) continue;
                $text = (string) ($p['bodyMarkdown'] ?? '') . "\n" . (string) ($p['bodyHtml'] ?? '');
                if (!preg_match_all('~\[event:\s*([a-z0-9][a-z0-9-]{0,80})\s*\]~i', $text, $m)) continue;
                foreach ($m[1] as $ref) {
                    // date-only refs are own-brand; full ids of the own brand
                    // are already in the events snapshot — only foreign ones sync.
                    if (!preg_match('/^(.+)-(\d{4}-\d{2}-\d{2})$/', strtolower($ref), $mm)) continue;
                    if ($mm[1] === $this->brand) continue;
                    $refs[$mm[1]][$mm[2]] = true;
                }
            }
        }
        ksort($refs);
        return array_map(fn(array $dates) => array_keys($dates), $refs);
    }

    private function readLocalJson(string $file): ?array
    {
        $data = json_decode((string) $this->readLocalRaw($file), true);
        return is_array($data) ? $data : null;
    }

    private function readLocalRaw(string $file): ?string
    {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        return $raw === false ? null : $raw;
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
            // include published flag: unpublishing leaves the content hash as is
            $parts[] = ($ev['id'] ?? '') . '=' . ($ev['hashes'][$lang] ?? '') . ($ev['published'] ?? false ? ':1' : ':0');
        }
        return hash('sha256', implode('|', $parts));
    }

    /** Per-language blog fingerprint from the manifest's blog layers + post hashes. */
    private function blogFingerprint(array $manifest, string $lang): string
    {
        $parts = [
            'blog.defaults:'      . ($manifest['layers']['blog.defaults']['hash'] ?? ''),
            'blog.strings.' . $lang . ':' . ($manifest['layers']['blog.strings.' . $lang]['hash'] ?? ''),
        ];
        $posts = $manifest['posts'] ?? [];
        usort($posts, fn($a, $b) => strcmp($a['id'] ?? '', $b['id'] ?? ''));
        foreach ($posts as $p) {
            // include published flag so unpublishing flips the fingerprint
            $parts[] = ($p['id'] ?? '') . '=' . ($p['hashes'][$lang] ?? '') . ($p['published'] ?? false ? ':1' : ':0');
        }
        return hash('sha256', implode('|', $parts));
    }

    /** Combined per-language fingerprint covering both events and blog posts —
     *  a change in either refetches the unified /snapshot for that language. */
    private function contentFingerprint(array $manifest, string $lang): string
    {
        return hash('sha256', $this->fingerprint($manifest, $lang) . '|' . $this->blogFingerprint($manifest, $lang));
    }

    /**
     * Manifest ⇄ snapshot consistency check. The API builds the manifest from
     * the store but serves /snapshot from a published cache that is regenerated
     * a few seconds after a publish — and /snapshot sends no Last-Modified, so
     * the only way to detect a stale snapshot is by content: every published
     * event/post id the manifest lists must be in the snapshot, and nothing
     * else (an unpublish lags the same way). Language does not matter — the
     * snapshot falls back to the primary language for untranslated content.
     * Returns null when consistent, else a short description for the log.
     */
    private function snapshotLag(array $manifest, array $events, array $posts): ?string
    {
        $diff = [];
        foreach (['events' => $events, 'posts' => $posts] as $kind => $items) {
            $want = $this->publishedIds($manifest[$kind] ?? []);
            $have = [];
            foreach ($items as $it) {
                if (is_array($it) && !empty($it['id'])) $have[] = (string) $it['id'];
            }
            foreach (['missing' => array_diff($want, $have), 'extra' => array_diff($have, $want)] as $what => $ids) {
                if ($ids === []) continue;
                $ids  = array_values(array_unique($ids));
                $more = count($ids) > 5 ? ' +' . (count($ids) - 5) . ' more' : '';
                $diff[] = "$kind $what " . implode(',', array_slice($ids, 0, 5)) . $more;
            }
        }
        return $diff ? implode('; ', $diff) : null;
    }

    /** @return string[] ids of the manifest entries flagged published */
    private function publishedIds(array $entries): array
    {
        $ids = [];
        foreach ($entries as $e) {
            if (is_array($e) && !empty($e['id']) && !empty($e['published'])) $ids[] = (string) $e['id'];
        }
        return $ids;
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

    private function validBlogPosts($posts): bool
    {
        if (!is_array($posts) || ($posts !== [] && !array_is_list($posts))) return false;
        foreach ($posts as $p) {
            if (!is_array($p) || empty($p['id']) || empty($p['slug']) || empty($p['title'])) return false;
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
            // Blog snapshot is required too; on first upgrade it's missing, so the
            // brand is "not primed" once and does a full fetch to populate it.
            if (!is_file($this->snapshotPath($lang)) || !is_file($this->blogSnapshotPath($lang))) return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    private function statusUrl(): string { return $this->apiBase . '/status'; }
    private function manifestUrl(): string { return $this->apiBase . '/brands/' . rawurlencode($this->brand) . '/manifest'; }
    private function snapshotUrl(string $lang): string
    {
        return $this->apiBase . '/brands/' . rawurlencode($this->brand) . '/snapshot?lang=' . rawurlencode($lang);
    }
    private function snapshotPath(string $lang): string { return $this->cacheDir . '/events.' . $lang . '.json'; }
    private function blogSnapshotPath(string $lang): string { return $this->cacheDir . '/blog.' . $lang . '.json'; }
    private function linkedPath(string $lang): string { return $this->cacheDir . '/linked-events.' . $lang . '.json'; }
    private function linkedEventUrl(string $brand, string $date, string $lang): string
    {
        return $this->apiBase . '/brands/' . rawurlencode($brand) . '/events/' . rawurlencode($date)
             . '?lang=' . rawurlencode($lang);
    }
}
