<?php

namespace App\Libraries;

/**
 * Slows down password guessing. Failed logins are already written to the
 * audit log (shared by every server instance), so they are counted there:
 * too many for one username, or from one IP address, within the window and
 * further attempts are refused until it passes. A successful login clears
 * the count for that username.
 */
class LoginThrottle
{
    public const WINDOW_MINUTES   = 15;
    public const MAX_PER_USERNAME = 5;
    public const MAX_PER_IP       = 20;

    /**
     * Minutes until another attempt is allowed; 0 when it is allowed now.
     */
    public static function minutesLeft(string $username, string $ip): int
    {
        if (! AuditLog::available()) {
            return self::cacheFallback($username);
        }

        $db    = db_connect();
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);

        $lastLogin = $db->table('audit_logs')->selectMax('created_at', 'at')
            ->where('action', 'Logged in')->where('username', $username)->where('created_at >=', $since)
            ->get()->getRow('at');
        $userFails = $db->table('audit_logs')->select('created_at')
            ->where('action', 'Failed login')->where('username', $username)->where('created_at >', $lastLogin ?? $since)
            ->orderBy('created_at', 'ASC')->get()->getResultArray();
        $ipFails = $ip === '' ? [] : $db->table('audit_logs')->select('created_at')
            ->where('action', 'Failed login')->where('ip_address', $ip)->where('created_at >', $since)
            ->orderBy('created_at', 'ASC')->get()->getResultArray();

        $wait = 0;
        foreach ([[$userFails, self::MAX_PER_USERNAME], [$ipFails, self::MAX_PER_IP]] as [$fails, $max]) {
            if (count($fails) >= $max) {
                // Allowed again once the oldest counted failure leaves the window.
                $freeAt = strtotime($fails[count($fails) - $max]['created_at']) + self::WINDOW_MINUTES * 60;
                $wait   = max($wait, (int) ceil(($freeAt - time()) / 60));
            }
        }

        return max(0, $wait);
    }

    /** Before the audit log table exists: a per-instance counter in the cache. */
    private static function cacheFallback(string $username): int
    {
        $throttler = service('throttler');

        return $throttler->check('login-' . md5(strtolower($username)), self::MAX_PER_USERNAME, self::WINDOW_MINUTES * MINUTE)
            ? 0
            : (int) ceil($throttler->getTokenTime() / 60);
    }
}
