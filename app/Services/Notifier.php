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

    public static function available(string $table = 'notifications'): bool
    {
        try {
            return db_connect()->tableExists($table);
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
