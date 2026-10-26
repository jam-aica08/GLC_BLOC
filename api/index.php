<?php

use CodeIgniter\Boot;
use Config\Paths;

// Vercel serverless entrypoint. The deployment filesystem is read-only, so
// CodeIgniter's writable directory is moved to /tmp (per-instance, ephemeral).

if (getenv('CI_ENVIRONMENT') === false) {
    putenv('CI_ENVIRONMENT=production');
    $_SERVER['CI_ENVIRONMENT'] = 'production';
}

// The runtime's php.ini has expose_php on, which adds "X-Powered-By: PHP/x.y.z"
// to every response and tells attackers exactly which PHP build to target.
header_remove('X-Powered-By');

// The function is only reachable through Vercel's edge, which sets this header
// itself; CI4 would otherwise ignore it because Vercel's proxy IPs aren't fixed.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// The visitor's address, which Vercel's edge always sets (clients cannot
// supply it). Without this every request looks like it came from the proxy,
// which would make per-address login limits and audit-log IPs useless.
if (filter_var($_SERVER['HTTP_X_REAL_IP'] ?? '', FILTER_VALIDATE_IP)) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_REAL_IP'];
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
