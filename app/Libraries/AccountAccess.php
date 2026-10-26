<?php

namespace App\Libraries;

/**
 * Whether a login may be used right now: the user account must be Active
 * and, for members, so must the membership. Checked at login and again on
 * every protected request, so deactivating someone takes effect at once.
 */
class AccountAccess
{
    public static function allowed(int $userId): bool
    {
        return self::isActive(self::state($userId));
    }

    /** The login's status, membership status and password hash (one query), or null. */
    public static function state(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return db_connect()->table('users u')
            ->select('u.account_status, u.password_hash, m.membership_status')
            ->join('members m', 'm.user_id = u.user_id', 'left')
            ->where('u.user_id', $userId)
            ->get()->getRowArray();
    }

    public static function isActive(?array $state): bool
    {
        return $state !== null
            && $state['account_status'] === 'Active'
            && ($state['membership_status'] === null || $state['membership_status'] === 'Active');
    }

    /**
     * A short fingerprint of the password hash, kept in the session at sign-in.
     * When the password changes it no longer matches, which ends every other
     * sign-in of that account (AuthFilter).
     */
    public static function passwordMark(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, 16);
    }

    /**
     * Whether the user is the super administrator, read from the database on
     * each call (not the session) so a change of role takes effect at once.
     * Only the super administrator may add admins, reset data, import data
     * and read the audit logs.
     */
    public static function isSuperAdmin(int $userId): bool
    {
        $db = db_connect();
        if ($userId <= 0 || ! $db->fieldExists('is_super_admin', 'users')) {
            return false;
        }

        return (bool) ($db->table('users')->select('is_super_admin')
            ->where('user_id', $userId)->get()->getRowArray()['is_super_admin'] ?? false);
    }
}
