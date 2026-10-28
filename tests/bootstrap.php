<?php

/** Builds and seeds the test database once, before any test opens a transaction. */
require __DIR__ . '/../vendor/autoload.php';

$db = __DIR__ . '/../database/testing.sqlite';
@unlink($db);
touch($db);
$env = 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=' . escapeshellarg(realpath($db)) . ' ';
$cmd = PHP_OS_FAMILY === 'Windows'
    ? 'set APP_ENV=testing&& set DB_CONNECTION=sqlite&& set DB_DATABASE=' . realpath($db) . '&& php "' . __DIR__ . '/../artisan" migrate:fresh --seed --force'
    : $env . 'php ' . escapeshellarg(__DIR__ . '/../artisan') . ' migrate:fresh --seed --force';
exec($cmd . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $out));
    exit(1);
}
