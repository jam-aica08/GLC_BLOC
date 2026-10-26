<?php

namespace App\Filters;

use App\Libraries\AccountAccess;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    /**
     * Minutes without any request before a sign-in ends. Open pages keep it
     * alive (their charts refresh every minute); a closed site does not.
     * Shorter than PHP's default session clean-up (24 minutes).
     */
    public const IDLE_MINUTES = 20;

    /** Session keys of a sign-in. */
    public const SIGN_IN_KEYS = ['user_id', 'username', 'role', 'member_id', 'is_super_admin', 'logged_in', 'last_activity', 'password_mark', 'activity_day'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (!session()->get('user_id')) {
            return $this->signedOut($request, 'Please log in to continue.');
        }

        // Session rows are never checked for age when read, so enforce the idle limit here.
        $last = (int) (session()->get('last_activity') ?? time());
        if (time() - $last > self::IDLE_MINUTES * 60) {
            return $this->signedOut($request, 'You were signed out after ' . self::IDLE_MINUTES . ' minutes without activity. Please sign in again.', true);
        }
        session()->set('last_activity', time());

        // A session kept open for days still counts as activity for the retention review (one write a day).
        if (session()->get('activity_day') !== date('Y-m-d')) {
            \App\Services\RetentionService::touch((int) session()->get('user_id'));
            session()->set('activity_day', date('Y-m-d'));
        }

        // Deactivated since signing in: end the session now, not when it expires.
        $state = AccountAccess::state((int) session()->get('user_id'));
        if (! AccountAccess::isActive($state)) {
            return $this->signedOut($request, 'This account is not active. Please contact the cooperative office.', true);
        }

        // Password changed since this sign-in (on another device, or by someone
        // who knew it): only the session that changed it carries the new mark.
        $mark = AccountAccess::passwordMark((string) $state['password_hash']);
        if (session()->get('password_mark') === null) {
            session()->set('password_mark', $mark);   // signed in before marks existed
        } elseif (! hash_equals((string) session()->get('password_mark'), $mark)) {
            return $this->signedOut($request, 'Your password was changed. Please sign in with the new password.', true);
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

    /**
     * Send a signed-out visitor to the login page. A background request from an
     * open page gets 401 JSON instead, so the page can go to the login page
     * itself rather than keep showing old figures.
     */
    private function signedOut(RequestInterface $request, string $message, bool $endSignIn = false)
    {
        if ($endSignIn) {
            session()->remove(self::SIGN_IN_KEYS);
            session()->regenerate(true);
        }
        if ($request instanceof \CodeIgniter\HTTP\IncomingRequest && $request->isAJAX()) {
            session()->setFlashdata('error', $message);   // shown on the login page the browser goes to next
            return service('response')->setStatusCode(401)
                ->setJSON(['signedOut' => true, 'message' => $message, 'login' => base_url('login')]);
        }

        return redirect()->to('/login')->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
