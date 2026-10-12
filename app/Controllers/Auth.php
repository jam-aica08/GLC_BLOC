<?php

namespace App\Controllers;

use App\Models\UserModel;

/**
 * Auth controller — login / logout.
 *
 * Fix 4.2 : logout is POST-only (prevents CSRF-triggered logouts via image/link).
 * Fix 4.9 : session ID is regenerated after successful login.
 */
class Auth extends BaseController
{
    // -------------------------------------------------------------------------
    // Login form
    // -------------------------------------------------------------------------

    /**
     * Display the login form (GET /).
     */
    public function index(): string
    {
        $session = session();

        $data = [
            'error' => $session->getFlashdata('login_error'),
        ];

        return view('auth/login', $data);
    }

    // -------------------------------------------------------------------------
    // Login action
    // -------------------------------------------------------------------------

    /**
     * Handle login POST (POST /login).
     *
     * Validates credentials, regenerates the session ID on success (Fix 4.9),
     * sets session data, then redirects to the role-appropriate dashboard.
     */
    public function login()
    {
        // Only accept POST
        if (! $this->request->is('post')) {
            return redirect()->to('/login');
        }

        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');

        // Basic input presence check (no blanks)
        if ($username === '' || $password === '') {
            session()->setFlashdata('login_error', 'Username and password are required.');
            return redirect()->to('/login');
        }

        // Fetch the user record
        $userModel = model(UserModel::class);
        $user      = $userModel->where('username', $username)
                               ->where('account_status', 'Active')
                               ->first();

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            session()->setFlashdata('login_error', 'Invalid username or password.');
            return redirect()->to('/login');
        }

        // Fetch role name
        $db   = db_connect();
        $role = $db->table('roles')
                   ->where('role_id', $user['role_id'])
                   ->get()
                   ->getRowArray();

        $roleName = $role['role_name'] ?? 'Member';

        // Fetch member_id for the member role
        $memberId = null;
        if (strtolower($roleName) === 'member') {
            $memberRow = $db->table('members')
                            ->where('user_id', $user['user_id'])
                            ->get()
                            ->getRowArray();
            $memberId = $memberRow['member_id'] ?? null;
        }

        // Fix 4.9: regenerate session ID to prevent session fixation
        $session = session();
        $session->regenerate(true);

        // Persist authentication data
        $session->set([
            'user_id'   => $user['user_id'],
            'username'  => $user['username'],
            'role'      => $roleName,
            'member_id' => $memberId,
            'logged_in' => true,
        ]);

        // Redirect to role dashboard
        if (strtolower($roleName) === 'admin') {
            return redirect()->to('/admin/dashboard');
        }

        return redirect()->to('/member/dashboard');
    }

    // -------------------------------------------------------------------------
    // Logout action
    // -------------------------------------------------------------------------

    /**
     * Handle logout (POST /logout — Fix 4.2: POST only).
     *
     * Destroys the session completely, then redirects to the login page.
     */
    public function logout()
    {
        // Only accept POST to prevent CSRF logout via GET requests
        if (! $this->request->is('post')) {
            return redirect()->to('/login');
        }

        $session = session();
        $session->destroy();

        return redirect()->to('/login');
    }
}
