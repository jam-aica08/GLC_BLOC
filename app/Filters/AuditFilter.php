<?php

namespace App\Filters;

use App\Libraries\AuditLog;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Records every POST made by a signed-in admin or member in the audit log
 * after it has run. Page views are not recorded.
 */
class AuditFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (strtolower($request->getMethod()) === 'post' && session()->get('user_id')) {
            $isJson = str_contains($response->getHeaderLine('Content-Type'), 'json');
            AuditLog::recordRequest($request->getUri()->getPath(), $isJson ? (string) $response->getBody() : null);
        }
    }
}
