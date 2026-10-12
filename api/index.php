<?php

use CodeIgniter\Boot;
use Config\Paths;

// Vercel serverless entrypoint. The deployment filesystem is read-only, so
// CodeIgniter's writable directory is moved to /tmp (per-instance, ephemeral).

if (getenv('CI_ENVIRONMENT') === false) {
    putenv('CI_ENVIRONMENT=production');
    $_SERVER['CI_ENVIRONMENT'] = 'production';
}

// The function is only reachable through Vercel's edge, which sets this header
// itself; CI4 would otherwise ignore it because Vercel's proxy IPs aren't fixed.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

$writable = '/tmp/writable';
foreach (['cache', 'logs', 'session', 'uploads', 'debugbar'] as $dir) {
    if (! is_dir($writable . '/' . $dir)) {
        @mkdir($writable . '/' . $dir, 0775, true);
    }
}

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require dirname(__DIR__) . '/app/Config/Paths.php';

$paths                    = new Paths();
$paths->writableDirectory = $writable;

require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
