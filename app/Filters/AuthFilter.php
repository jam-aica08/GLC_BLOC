<?php

namespace App\Filters;

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

        // Check role-based access (FIX 4.9: server-side role enforcement)
        if ($arguments) {
            $required = $arguments[0] ?? null;
            $userRole = session()->get('role');

            if ($required === 'admin' && $userRole !== 'Admin') {
                return redirect()->to('/login')->with('error', 'Unauthorized access.');
            }
            if ($required === 'member' && $userRole !== 'Member') {
                return redirect()->to('/login')->with('error', 'Unauthorized access.');
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
