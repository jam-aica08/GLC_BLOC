<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseConfig
{
    /**
     * Configures the global filters for all routes.
     * FIX 4.1: CSRF filter is now ENABLED globally.
     */
    public array $globals = [
        'before' => [
            'csrf',   // FIX 4.1: CSRF protection enabled globally
            // 'invalidchars',
            // 'honeypot',
        ],
        'after' => [
            'toolbar',
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    public array $methods = [];

    public array $filters = [
        'auth' => [
            'before' => [
                'admin/*',
                'member/*',
            ],
        ],
        'audit' => [
            'after' => [
                'admin/*',
                'member/*',
            ],
        ],
    ];

    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'auth'          => \App\Filters\AuthFilter::class,
        'audit'         => \App\Filters\AuditFilter::class,
    ];
}
