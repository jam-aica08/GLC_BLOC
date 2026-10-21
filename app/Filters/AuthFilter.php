<?php

namespace App\Filters;

use App\Libraries\AccountAccess;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (!session()->get('user_id')) {
            return redirect()->to('/login')->with('error', 'Please log in to continue.');
        }

        // Deactivated since signing in: end the session now, not when it expires.
        if (! AccountAccess::allowed((int) session()->get('user_id'))) {
            session()->remove(['user_id', 'username', 'role', 'member_id', 'is_super_admin', 'logged_in']);
            session()->regenerate(true);
            return redirect()->to('/login')->with('error', 'This account is not active. Please contact the cooperative office.');
        }

        // Check role-based access (FIX 4.9: server-side role enforcement)
        if ($arguments) {
            $required = $arguments[0] ?? null;
            $userRole = session()->get('role');

            if ($required === 'admin' && strtolower((string) $userRole) !== 'admin') {
                return redirect()->to('/login')->with('error', 'Unauthorized access.');
            }
            if ($required === 'member' && strtolower((string) $userRole) !== 'member') {
                return redirect()->to('/login')->with('error', 'Unauthorized access.');
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
