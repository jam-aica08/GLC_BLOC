<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Browser security headers on every response. The policy stops other sites
 * framing the app (clickjacking on approve/reject buttons) and limits where
 * forms may post; scripts are not restricted because the pages use inline ones.
 */
class SecurityHeaders implements FilterInterface
{
    private const HEADERS = [
        'X-Frame-Options'         => 'SAMEORIGIN',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Content-Security-Policy' => "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'",
    ];

    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        foreach (self::HEADERS as $name => $value) {
            if (! $response->hasHeader($name)) {
                $response->setHeader($name, $value);
            }
        }
    }
}
