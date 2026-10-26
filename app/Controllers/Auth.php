<?php

namespace App\Controllers;

use App\Libraries\AccountAccess;
use App\Libraries\AuditLog;
use App\Libraries\LoginThrottle;
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
    public function index(): string|\CodeIgniter\HTTP\RedirectResponse
    {
        // Visitors without a session cookie can't be logged in or have flash
        // messages, so skip starting a session (a database round trip on Vercel).
        if ($this->request->getCookie(config('Session')->cookieName) === null) {
            return view('auth/login', ['error' => null, 'success' => null, 'username' => '']);
        }

        $session = session();

        // Redirect already-authenticated users to their dashboard
        if ($session->get('logged_in')) {
            $role = strtolower((string) $session->get('role'));
            return redirect()->to($role === 'admin' ? '/admin/dashboard' : '/member/dashboard');
        }

        return view('auth/login', [
            'error'    => $session->getFlashdata('error'),
            'success'  => $session->getFlashdata('success'),
            'username' => old('username'),
        ]);
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
            $this->clearAuth();
            session()->setFlashdata('error', 'Username and password are required.');
            return redirect()->to('/login');
        }

        // Too many recent failures for this username or address: refuse before checking the password.
        $wait = LoginThrottle::minutesLeft($username, (string) $this->request->getIPAddress());
        if ($wait > 0) {
            AuditLog::record('Login throttled', null, null, 'Sign-in refused after repeated failed attempts.', false,
                ['user_id' => null, 'username' => $username, 'role' => null]);
            $this->clearAuth();
            session()->setFlashdata('error', "Too many failed sign-in attempts. Try again in {$wait} minute" . ($wait === 1 ? '' : 's') . '.');
            return redirect()->to('/login');
        }

        // Fetch the user record
        $userModel = model(UserModel::class);
        $user      = $userModel->where('username', $username)
                               ->where('account_status', 'Active')
                               ->first();

        // An unknown username still pays for one password check, so the reply
        // time does not reveal which usernames exist.
        if (! $user) {
            password_verify($password, self::decoyHash());
        }

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            // Record the attempt (the username typed, never the password).
            AuditLog::record('Failed login', 'user', $user['user_id'] ?? null, 'Invalid username or password.', false,
                ['user_id' => $user['user_id'] ?? null, 'username' => $username, 'role' => null]);
            $this->clearAuth();
            session()->setFlashdata('error', 'Invalid username or password.');
            return redirect()->to('/login');
        }

        // A deactivated membership cannot sign in either.
        if (! AccountAccess::allowed((int) $user['user_id'])) {
            AuditLog::record('Failed login', 'user', $user['user_id'], 'Account is not active.', false,
                ['user_id' => $user['user_id'], 'username' => $username, 'role' => null]);
            $this->clearAuth();
            session()->setFlashdata('error', 'This account is not active. Please contact the cooperative office.');
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
        $isSuper = strtolower($roleName) === 'admin' && ! empty($user['is_super_admin']);
        $session->set([
            'user_id'        => $user['user_id'],
            'username'       => $user['username'],
            'role'           => $roleName,
            'member_id'      => $memberId,
            'is_super_admin' => $isSuper,
            'logged_in'      => true,
            'last_activity'  => time(),   // AuthFilter ends the sign-in after IDLE_MINUTES without a request
            'password_mark'  => AccountAccess::passwordMark($user['password_hash']),   // a password change ends other sign-ins
        ]);
        AuditLog::record('Logged in', 'user', $user['user_id']);
        \App\Services\RetentionService::touch((int) $user['user_id']);   // inactivity is counted from here
        $session->set('activity_day', date('Y-m-d'));

        // Redirect to role dashboard
        if (strtolower($roleName) === 'admin') {
            return redirect()->to('/admin/dashboard');
        }

        return redirect()->to('/member/dashboard');
    }

    /**
     * Drop any existing login without destroying the session, so a flash
     * message set afterwards survives the redirect.
     */
    private function clearAuth(): void
    {
        $session = session();
        $session->remove(\App\Filters\AuthFilter::SIGN_IN_KEYS);
        $session->regenerate(true);
    }

    /**
     * A password hash nobody can match, made with the current default cost so
     * checking it takes as long as checking a real one. Cached per instance.
     */
    private static function decoyHash(): string
    {
        return cache()->remember('login_decoy_hash', DAY, static fn () => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
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
        if ($session->get('user_id')) {
            AuditLog::record('Logged out', 'user', $session->get('user_id'));
        }
        $session->destroy();

        return redirect()->to('/login');
    }
}
