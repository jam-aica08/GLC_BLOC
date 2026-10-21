<?php

namespace App\Services;

use Throwable;

/**
 * Private notifications for members. Each row belongs to one member, so a
 * member only ever sees their own. An event key (for example
 * "contribution-approved-42") stops the same event being notified twice.
 */
class Notifier
{
    public static function available(): bool
    {
        try {
            return db_connect()->tableExists('notifications');
        } catch (Throwable) {
            return false;
        }
    }

    public static function toMember(int $memberId, string $type, string $title, string $message, ?string $link = null, ?string $eventKey = null): void
    {
        try {
            if ($memberId <= 0 || ! self::available()) {
                return;
            }
            $db = db_connect();
            if ($eventKey !== null && $db->table('notifications')->where('member_id', $memberId)->where('event_key', $eventKey)->countAllResults() > 0) {
                return;
            }
            $db->table('notifications')->insert([
                'member_id'  => $memberId,
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
            log_message('error', 'Notifier::toMember failed: ' . $e->getMessage());
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

    public static function unreadCount(int $memberId): int
    {
        return self::available()
            ? db_connect()->table('notifications')->where('member_id', $memberId)->where('is_read', 0)->countAllResults()
            : 0;
    }

    public static function latest(int $memberId, int $limit = 8): array
    {
        return self::available()
            ? db_connect()->table('notifications')->where('member_id', $memberId)
                ->orderBy('created_at', 'DESC')->orderBy('notification_id', 'DESC')->limit($limit)->get()->getResultArray()
            : [];
    }

    /** Mark one (only if it belongs to $memberId) or all of a member's notifications read. */
    public static function markRead(int $memberId, ?int $notificationId = null): int
    {
        if (! self::available()) {
            return 0;
        }
        $q = db_connect()->table('notifications')->where('member_id', $memberId)->where('is_read', 0);
        if ($notificationId !== null) {
            $q->where('notification_id', $notificationId);
        }
        $q->update(['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')]);

        return db_connect()->affectedRows();
    }
}
