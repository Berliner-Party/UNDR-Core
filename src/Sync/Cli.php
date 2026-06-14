<?php
declare(strict_types=1);

namespace Undr\Core\Sync;

// ---------------------------------------------------------------------------
// Thin CLI wrapper around UndrSync. Each site's bin/sync.php becomes a 3-liner:
//
//   require dirname(__DIR__) . '/vendor/autoload.php';
//   exit(\Undr\Core\Sync\Cli::run(require dirname(__DIR__) . '/config/undr.php'));
//
// Pulls the latest event data from the UNDR API into the local cache the website
// reads. Returns 0 on success OR graceful degrade (API down → last-good cache
// still serves); returns 1 only on a hard module fault. One structured log line
// per run on stdout/stderr.
// ---------------------------------------------------------------------------
final class Cli
{
    public static function run(array $config): int
    {
        $result = (new UndrSync($config))->sync();
        fwrite($result->exitCode === 0 ? STDOUT : STDERR, $result->toLogLine() . "\n");
        return $result->exitCode;
    }
}
