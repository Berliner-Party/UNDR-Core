<?php
declare(strict_types=1);

namespace Undr\Core\Sync;

// Value object returned by UndrSync::sync(). Drives the cron exit code + log line.
final class SyncResult
{
    public bool   $skipped       = false;   // another run held the lock
    public bool   $degraded      = false;   // API unreachable/bad → served last-good cache
    public string $source        = 'none';  // 'api' | 'not-modified' | 'cache' | 'none'
    public array  $changedLangs  = [];      // langs whose snapshot was rewritten this run
    public int    $eventsWritten = 0;
    public int    $assetsMirrored= 0;
    public array  $errors        = [];      // [['kind'=>'network|http|json|schema','msg'=>...], ...]
    public int    $exitCode      = 0;       // 0 = ok / ok-degraded, 1 = hard module fault
    public string $startedAt     = '';
    public float  $durationMs    = 0.0;

    public function addError(string $kind, string $msg): void
    {
        $this->errors[] = ['kind' => $kind, 'msg' => $msg];
    }

    public function toLogLine(): string
    {
        $parts = [
            $this->exitCode === 0 ? 'ok' : 'FAIL',
            'source=' . $this->source,
            'degraded=' . ($this->degraded ? '1' : '0'),
            'skipped=' . ($this->skipped ? '1' : '0'),
            'langs=' . ($this->changedLangs ? implode(',', $this->changedLangs) : '-'),
            'events=' . $this->eventsWritten,
            'assets=' . $this->assetsMirrored,
            'ms=' . (int) $this->durationMs,
        ];
        if ($this->errors) {
            $parts[] = 'errors=' . implode('|', array_map(
                fn($e) => $e['kind'] . ':' . $e['msg'],
                $this->errors
            ));
        }
        return $this->startedAt . ' undr-sync ' . implode(' ', $parts);
    }
}
