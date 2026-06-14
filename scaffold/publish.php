<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Publish UNDR Core's shared browser assets into the consuming site's
// public/assets/. Invoked by each site's Composer post-install-cmd /
// post-update-cmd (Composer runs scripts with CWD = the site root):
//
//   php vendor/undr/core/scaffold/publish.php
//
// Copies (never symlinks) so the files are first-party under /assets/ — that
// keeps CSP at 'self' and makes ?v=filemtime cache-busting resolve against real
// on-disk files. Re-run on every deploy so a new undr/core version refreshes the
// shared CSS/JS. Optionally pass an explicit destination dir as the first arg.
// ---------------------------------------------------------------------------

$srcRoot = __DIR__ . '/../assets';
$dest    = $argv[1] ?? (getcwd() . '/public/assets');

if (!is_dir($srcRoot)) {
    fwrite(STDERR, "undr publish: source assets dir missing: $srcRoot\n");
    exit(1);
}
if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
    fwrite(STDERR, "undr publish: cannot create destination: $dest\n");
    exit(1);
}

$copied = 0;
$failed = 0;
foreach (['css', 'js'] as $kind) {
    $dir = $srcRoot . '/' . $kind;
    if (!is_dir($dir)) continue;
    foreach (glob($dir . '/*') as $file) {
        if (!is_file($file)) continue;
        $target = $dest . '/' . basename($file);
        if (@copy($file, $target)) {
            $copied++;
        } else {
            $failed++;
            fwrite(STDERR, 'undr publish: failed to copy ' . basename($file) . "\n");
        }
    }
}

fwrite(STDOUT, "undr publish: $copied shared asset(s) → $dest" . ($failed ? " ($failed failed)" : '') . "\n");
exit($failed ? 1 : 0);
