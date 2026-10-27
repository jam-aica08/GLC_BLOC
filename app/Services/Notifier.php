<?php

namespace App\Services;

use Throwable;

/**
 * Private notifications. Member rows live in `notifications` (one row per
 * member) and administrator rows in `admin_notifications` (one row per
 * administrator), so read status is personal. An event key (for example
 * "contribution-approved-42") stops the same event being notified twice.
 */
class Notifier
{
    /** [table, owner column] for members and for administrators. */
    private const MEMBER = ['notifications', 'member_id'];
    private const ADMIN  = ['admin_notifications', 'user_id'];

    /**
     * Event keys that stay unread when the bell is opened: something the
     * person still has to act on, or a refusal they should not miss. The rest
     * (confirmations, approvals, dividends, announcements) count as seen once
     * the list has been opened. Review items clear themselves when handled
     * (resolveForAdmins), activation reminders when the account activates.
     */
    private const IMPORTANT = [
        'notifications'       => ['activate-', 'contribution-rejected-', 'payment-rejected-', 'loan-rejected-'],
        'admin_notifications' => ['review-', 'ledger-tampered-'],
    ];

    /** Whether a notification keeps its unread badge until it is dealt with. */
    public static function isImportant(string $area, ?string $eventKey): bool
    {
        foreach (self::IMPORTANT[$area === 'admin' ? self::ADMIN[0] : self::MEMBER[0]] as $prefix) {
            if (str_starts_with((string) $eventKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function available(string $table = 'notifications'): bool
    {
        try {
            return \App\Libraries\SchemaCache::passes('table_' . $table, static fn () => db_connect()->tableExists($table));
        } catch (Throwable) {
            return false;
        }
    }

    public static function toMember(int $memberId, string $type, string $title, string $message, ?string $link = null, ?string $eventKey = null): void
    {
        self::insert(self::MEMBER, $memberId, $type, $title, $message, $link, $eventKey);
    }

    private static function insert(array $box, int $ownerId, string $type, string $title, string $message, ?string $link, ?string $eventKey): void
    {
        [$table, $owner] = $box;
        try {
            if ($ownerId <= 0 || ! self::available($table)) {
                return;
            }
            $db = db_connect();
            if ($eventKey !== null && $db->table($table)->where($owner, $ownerId)->where('event_key', $eventKey)->countAllResults() > 0) {
                return;
            }
            $db->table($table)->insert([
                $owner       => $ownerId,
                'type'       => $type,
                'title'      => mb_substr($title, 0, 150),
                'message'    => mb_strimwidth($message, 0, 500, '…'),
                'link'       => $link,
                'event_key'  => $eventKey,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // A notification must never break the action that triggered it
            // (a duplicate event key racing in lands here too).
            log_message('error', 'Notifier::insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Keep one "activate your account" reminder per Pending account in the
     * member's notifications, with the amount still needed. A changed amount
     * replaces the old reminder; an account that is no longer Pending loses it.
     * A deactivated (Inactive) account never gets one: deactivation stops
     * contribution reminders, nothing else.
     *
     * @param list<array> $accounts rows with account_id, account_number, account_status and standing.join_balance
     */
    public static function syncActivationReminders(int $memberId, array $accounts): void
    {
        try {
            if ($memberId <= 0 || ! self::available()) {
                return;
            }
            $db = db_connect();
            foreach ($accounts as $acc) {
                $prefix    = 'activate-' . (int) $acc['account_id'] . '-';
                $remaining = (float) ($acc['standing']['join_balance'] ?? 0);
                $pending   = ($acc['account_status'] ?? '') === 'Pending' && $remaining > 0;
                $key       = $prefix . (int) round($remaining * 100);

                if ($pending && $db->table('notifications')->where('member_id', $memberId)->where('event_key', $key)->countAllResults() > 0) {
                    continue;
                }
                $db->table('notifications')->where('member_id', $memberId)->like('event_key', $prefix, 'after')->delete();
                if ($pending) {
                    self::toMember($memberId, 'account', 'Activate account ' . $acc['account_number'],
                        'Account ' . $acc['account_number'] . ' becomes active once PHP ' . number_format($remaining, 2)
                        . ' more is contributed (and approved).', 'member/contributions', $key);
                }
            }
        } catch (Throwable $e) {
            log_message('error', 'Notifier::syncActivationReminders failed: ' . $e->getMessage());
        }
    }

    /** Notify the member who owns an account. */
    public static function toAccountOwner(int $accountId, string $type, string $title, string $message, ?string $link = null, ?string $eventKey = null): void
    {
        try {
            $row = db_connect()->table('cooperative_accounts')->select('member_id')->where('account_id', $accountId)->get()->getRowArray();
        } catch (Throwable) {
            return;
        }
        if ($row) {
            self::toMember((int) $row['member_id'], $type, $title, $message, $link, $eventKey);
        }
    }

    /** An announcement for every active member. Returns how many were notified. */
    public static function announce(string $title, string $message): int
    {
        if (! self::available()) {
            return 0;
        }
        $key     = 'announcement-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $members = db_connect()->table('members')->select('member_id')->where('membership_status', 'Active')->get()->getResultArray();
        foreach ($members as $m) {
            self::toMember((int) $m['member_id'], 'announcement', $title, $message, null, $key);
        }

        return count($members);
    }

    /**
     * Something for the administrators: every active administrator gets their
     * own copy, except $exceptUserId (the administrator who caused it).
     */
    public static function toAdmins(string $type, string $title, string $message, ?string $link = null, ?string $eventKey = null, int $exceptUserId = 0): void
    {
        if (! self::available('admin_notifications')) {
            return;
        }
        try {
            $admins = db_connect()->table('users u')->select('u.user_id')
                ->join('roles r', 'r.role_id = u.role_id')
                ->where('LOWER(r.role_name)', 'admin')->where('u.account_status', 'Active')
                ->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'Notifier::toAdmins failed: ' . $e->getMessage());
            return;
        }
        foreach ($admins as $a) {
            if ((int) $a['user_id'] !== $exceptUserId) {
                self::insert(self::ADMIN, (int) $a['user_id'], $type, $title, $message, $link, $eventKey);
            }
        }
    }

    /** The item behind an administrator notification was handled: mark it read for every administrator. */
    public static function resolveForAdmins(string $eventKey): void
    {
        try {
            if (self::available('admin_notifications')) {
                db_connect()->table('admin_notifications')->where('event_key', $eventKey)->where('is_read', 0)
                    ->update(['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')]);
            }
        } catch (Throwable $e) {
            log_message('error', 'Notifier::resolveForAdmins failed: ' . $e->getMessage());
        }
    }

    public static function unreadCount(int $memberId): int
    {
        return self::countUnread(self::MEMBER, $memberId);
    }

    public static function latest(int $memberId, int $limit = 8): array
    {
        return self::fetch(self::MEMBER, $memberId, $limit);
    }

    /** Mark one (only if it belongs to $memberId) or all of a member's notifications read. */
    public static function markRead(int $memberId, ?int $notificationId = null): int
    {
        return self::read(self::MEMBER, $memberId, $notificationId);
    }

    /** The member opened the bell: mark everything except important notifications read. */
    public static function markSeen(int $memberId): int
    {
        return self::seen(self::MEMBER, $memberId);
    }

    /** The administrator opened the bell: mark everything except important notifications read. */
    public static function adminMarkSeen(int $userId): int
    {
        return self::seen(self::ADMIN, $userId);
    }

    public static function adminUnreadCount(int $userId): int
    {
        return self::countUnread(self::ADMIN, $userId);
    }

    public static function adminLatest(int $userId, int $limit = 8): array
    {
        return self::fetch(self::ADMIN, $userId, $limit);
    }

    /** Mark one (only if it belongs to $userId) or all of an administrator's notifications read. */
    public static function adminMarkRead(int $userId, ?int $notificationId = null): int
    {
        return self::read(self::ADMIN, $userId, $notificationId);
    }

    private static function countUnread(array $box, int $ownerId): int
    {
        [$table, $owner] = $box;

        return self::available($table)
            ? db_connect()->table($table)->where($owner, $ownerId)->where('is_read', 0)->countAllResults()
            : 0;
    }

    private static function fetch(array $box, int $ownerId, int $limit): array
    {
        [$table, $owner] = $box;

        return self::available($table)
            ? db_connect()->table($table)->where($owner, $ownerId)
                ->orderBy('created_at', 'DESC')->orderBy('notification_id', 'DESC')->limit($limit)->get()->getResultArray()
            : [];
    }

    private static function seen(array $box, int $ownerId): int
    {
        [$table, $owner] = $box;
        if (! self::available($table)) {
            return 0;
        }
        $q = db_connect()->table($table)->where($owner, $ownerId)->where('is_read', 0)
            ->groupStart()->where('event_key', null);
        $q->orGroupStart();
        foreach (self::IMPORTANT[$table] as $prefix) {
            $q->notLike('event_key', $prefix, 'after');
        }
        $q->groupEnd()->groupEnd()
          ->update(['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')]);

        return db_connect()->affectedRows();
    }

    private static function read(array $box, int $ownerId, ?int $notificationId): int
    {
        [$table, $owner] = $box;
        if (! self::available($table)) {
            return 0;
        }
        $q = db_connect()->table($table)->where($owner, $ownerId)->where('is_read', 0);
        if ($notificationId !== null) {
            $q->where('notification_id', $notificationId);
        }
        $q->update(['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')]);

        return db_connect()->affectedRows();
    }
}
