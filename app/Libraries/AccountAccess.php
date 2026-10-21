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
        if ($userId <= 0) {
            return false;
        }

        $row = db_connect()->table('users u')
            ->select('u.account_status, m.membership_status')
            ->join('members m', 'm.user_id = u.user_id', 'left')
            ->where('u.user_id', $userId)
            ->get()->getRowArray();

        return $row !== null
            && $row['account_status'] === 'Active'
            && ($row['membership_status'] === null || $row['membership_status'] === 'Active');
    }
}
