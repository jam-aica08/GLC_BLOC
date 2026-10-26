<?php

namespace App\Services;

use App\Libraries\AuditLog;
use App\Libraries\SchemaCache;
use Config\Retention;

/**
 * Carries out the data retention schedule in Config\Retention.
 *
 * run() is the daily review (Vercel cron, `php spark retention:run`, or the
 * super admin's "Run review now"): it deletes expired sessions, logs,
 * notifications and rejected requests, flags members inactive for
 * $inactiveFlagMonths and deactivates those inactive for
 * $inactiveDeactivateMonths. It never deletes financial records.
 *
 * Removing a member's sign-in data and anonymizing their name are manual
 * steps for the super administrator (removeLoginData(), anonymize()), and a
 * legal hold stops every automatic and manual step for that member.
 */
class RetentionService
{
    private Retention $config;
    private int $now;

    public function __construct(?Retention $config = null, ?int $now = null)
    {
        $this->config = $config ?? config('Retention');
        $this->now    = $now ?? time();
    }

    private function db()
    {
        return db_connect();
    }

    /** False until "Apply Database Updates" has added the retention columns. */
    public static function available(): bool
    {
        try {
            return SchemaCache::passes('retention_columns', static function (): bool {
                $db = db_connect();

                return $db->fieldExists('last_activity_at', 'users') && $db->fieldExists('legal_hold', 'members');
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private function ago(string $interval): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . $interval, $this->now));
    }

    // =========================================================================
    // Daily review
    // =========================================================================

    /**
     * @return array<string, int> what was done, e.g. ['sessions deleted' => 3, 'members flagged' => 1]
     */
    public function run(?array $actor = null): array
    {
        $c       = $this->config;
        $db      = $this->db();
        $summary = [];

        if ($db->tableExists('ci_sessions')) {
            $db->table('ci_sessions')->where('timestamp <', $this->ago("{$c->sessionDays} days"))->delete();
            $summary['sessions deleted'] = $db->affectedRows();
        }

        if (AuditLog::available()) {
            $db->table('audit_logs')->whereIn('action', $c->securityActions)
               ->where('created_at <', $this->ago("{$c->securityLogDays} days"))->delete();
            $summary['sign-in log entries deleted'] = $db->affectedRows();

            $db->table('audit_logs')->where('created_at <', $this->ago("{$c->auditLogYears} years"))->delete();
            $summary['audit log entries deleted'] = $db->affectedRows();
        }

        foreach (['notifications', 'admin_notifications'] as $table) {
            if (! $db->tableExists($table)) {
                continue;
            }
            $db->table($table)->where('is_read', 1)->where('read_at <', $this->ago("{$c->readNotificationDays} days"))->delete();
            $deleted = $db->affectedRows();
            $db->table($table)->where('is_read', 0)->where('created_at <', $this->ago("{$c->unreadNotificationDays} days"))->delete();
            $summary['notifications deleted'] = ($summary['notifications deleted'] ?? 0) + $deleted + $db->affectedRows();
        }

        if ($db->tableExists('account_requests')) {
            $db->table('account_requests')->where('status', 'Rejected')
               ->where('reviewed_at <', $this->ago("{$c->rejectedRequestDays} days"))->delete();
            $summary['rejected requests deleted'] = $db->affectedRows();
        }

        if (self::available()) {
            $summary += $this->reviewInactiveMembers();
        }

        AuditLog::record('Ran data retention review', null, null, self::describe($summary), true,
            $actor ?? ['user_id' => null, 'username' => 'system', 'role' => 'System']);

        return $summary;
    }

    /** "3 sessions deleted, 1 member flagged" (or "nothing was due"). */
    public static function describe(array $summary): string
    {
        $parts = [];
        foreach ($summary as $what => $n) {
            if ($n > 0) {
                // "1 member flagged", "1 entry deleted": singular for one.
                $parts[] = $n . ' ' . ($n === 1 ? preg_replace(['/^(\S+?)ies\b/', '/^(\S+?)s\b/', '/ entries\b/', '/ flags\b/', '/ requests\b/'], ['$1y', '$1', ' entry', ' flag', ' request'], $what) : $what);
            }
        }

        return $parts === [] ? 'Nothing was due.' : implode(', ', $parts) . '.';
    }

    /**
     * Flag members inactive for $inactiveFlagMonths (and tell them), deactivate
     * those inactive for $inactiveDeactivateMonths, and clear the flag of
     * anyone active again. Members on legal hold or with an open loan or
     * waiting payment are never deactivated here.
     *
     * @return array<string, int>
     */
    private function reviewInactiveMembers(): array
    {
        $c          = $this->config;
        $db         = $this->db();
        $flagBefore = $this->ago("{$c->inactiveFlagMonths} months");
        $deactBefore = $this->ago("{$c->inactiveDeactivateMonths} months");
        $nowSql     = date('Y-m-d H:i:s', $this->now);
        $done       = ['members flagged as inactive' => 0, 'members deactivated for inactivity' => 0, 'inactivity flags cleared' => 0];

        foreach ($this->membersWithActivity()->where('m.membership_status', 'Active')->get()->getResultArray() as $m) {
            $memberId = (int) $m['member_id'];
            $last     = $m['last_activity'];

            if ($m['retention_flagged_at'] !== null && $last > $m['retention_flagged_at']) {
                $db->table('members')->where('member_id', $memberId)->update(['retention_flagged_at' => null]);
                $done['inactivity flags cleared']++;
                continue;
            }
            if ((int) $m['legal_hold'] === 1 || $last >= $flagBefore) {
                continue;
            }

            if ($last < $deactBefore && $this->blockers($memberId) === []) {
                $db->table('members')->where('member_id', $memberId)->update([
                    'membership_status'     => 'Inactive',
                    'retention_flagged_at'  => $m['retention_flagged_at'] ?? $nowSql,
                    'retention_reviewed_at' => $nowSql,
                ]);
                $db->table('users')->where('user_id', (int) $m['user_id'])->update(['account_status' => 'Inactive']);
                Notifier::toMember($memberId, 'account', 'Membership deactivated for inactivity',
                    "Your membership had no activity for {$c->inactiveDeactivateMonths} months and was deactivated under the cooperative's data retention policy. "
                    . 'Your accounts and their records are kept. Contact the cooperative office to reactivate it.',
                    'member/profile', 'retention-deactivated-' . $memberId . '-' . date('Ymd', $this->now));
                $done['members deactivated for inactivity']++;
                continue;
            }

            if ($m['retention_flagged_at'] === null) {
                $db->table('members')->where('member_id', $memberId)->update(['retention_flagged_at' => $nowSql]);
                Notifier::toMember($memberId, 'account', 'Your membership looks inactive',
                    "There has been no sign-in, contribution or loan payment for {$c->inactiveFlagMonths} months. "
                    . "Without activity, the membership is deactivated after {$c->inactiveDeactivateMonths} months (your records are kept). "
                    . 'Signing in is enough to keep it active.',
                    'privacy', 'retention-flagged-' . $memberId . '-' . date('Ymd', $this->now));
                $done['members flagged as inactive']++;
            }
        }

        return $done;
    }

    /**
     * Every member with their latest activity: the later of the last sign-in,
     * last contribution, last loan payment and the membership date.
     */
    private function membersWithActivity()
    {
        // Payment dates, not when the row was written: imported history counts from when it was paid.
        $contribution = '(SELECT CAST(MAX(c.payment_date) AS DATETIME) FROM contributions c JOIN cooperative_accounts ca ON ca.account_id = c.account_id WHERE ca.member_id = m.member_id)';
        $payment      = '(SELECT CAST(MAX(lp.payment_date) AS DATETIME) FROM loan_payments lp JOIN loans l ON l.loan_id = lp.loan_id JOIN cooperative_accounts ca ON ca.account_id = l.account_id WHERE ca.member_id = m.member_id)';
        $lastTx       = '(SELECT MAX(fr.transaction_date) FROM financial_records fr JOIN cooperative_accounts ca ON ca.account_id = fr.account_id WHERE ca.member_id = m.member_id)';
        $joined       = "CAST(m.membership_date AS DATETIME)";

        return $this->db()->table('members m')
            ->select("m.member_id, m.user_id, m.member_number, m.first_name, m.last_name, m.membership_status, m.membership_date,
                      m.retention_flagged_at, m.retention_reviewed_at, m.legal_hold, m.legal_hold_reason, m.login_data_removed_at, m.anonymized_at,
                      u.username, u.email, u.last_activity_at,
                      GREATEST(COALESCE(u.last_activity_at, {$joined}), COALESCE({$contribution}, {$joined}), COALESCE({$payment}, {$joined})) AS last_activity,
                      COALESCE({$lastTx}, m.membership_date) AS last_transaction", false)
            ->join('users u', 'u.user_id = m.user_id', 'left');
    }

    /**
     * Why a member's data cannot be deactivated or removed yet: an open loan,
     * a contribution or payment waiting for approval, or a legal hold.
     *
     * @return list<string>
     */
    public function blockers(int $memberId): array
    {
        $db       = $this->db();
        $blockers = [];

        $openLoans = $db->table('loans l')->join('cooperative_accounts ca', 'ca.account_id = l.account_id')
            ->where('ca.member_id', $memberId)->whereIn('l.loan_status', ['Pending', 'Approved', 'Active'])->countAllResults();
        if ($openLoans > 0) {
            $blockers[] = 'has an open loan';
        }
        $waiting = $db->table('contributions c')->join('cooperative_accounts ca', 'ca.account_id = c.account_id')
            ->where('ca.member_id', $memberId)->where('c.payment_status', 'Pending')->countAllResults()
            + $db->table('loan_payments lp')->join('loans l', 'l.loan_id = lp.loan_id')->join('cooperative_accounts ca', 'ca.account_id = l.account_id')
            ->where('ca.member_id', $memberId)->where('lp.payment_status', 'Pending')->countAllResults();
        if ($waiting > 0) {
            $blockers[] = 'has payments waiting for approval';
        }
        $hold = $db->table('members')->select('legal_hold')->where('member_id', $memberId)->get()->getRowArray();
        if ((int) ($hold['legal_hold'] ?? 0) === 1) {
            $blockers[] = 'is on legal hold';
        }

        return $blockers;
    }

    // =========================================================================
    // Review list for the super administrator
    // =========================================================================

    /**
     * Members the retention policy needs a person to look at, with the stage
     * each is at:
     *  - flagged:    active, but inactive for $inactiveFlagMonths (deactivated automatically later)
     *  - login_data: deactivated and inactive for $inactiveLoginDataMonths; sign-in data can be removed
     *  - anonymize:  sign-in data removed and $financialYears passed since the last transaction; the name can be anonymized
     *  - hold:       on legal hold (nothing happens until it is released)
     *
     * @return list<array>
     */
    public function reviewList(): array
    {
        $c              = $this->config;
        $flagBefore     = $this->ago("{$c->inactiveFlagMonths} months");
        $loginDataBefore = $this->ago("{$c->inactiveLoginDataMonths} months");
        $rows           = [];

        foreach ($this->membersWithActivity()->where('m.anonymized_at', null)->orderBy('last_activity', 'ASC')->get()->getResultArray() as $m) {
            $memberId = (int) $m['member_id'];
            $stage    = null;

            if ((int) $m['legal_hold'] === 1) {
                $stage = 'hold';
            } elseif ($m['login_data_removed_at'] !== null) {
                $stage = $this->financialPeriodEnded($m['last_transaction']) ? 'anonymize' : null;
            } elseif ($m['membership_status'] !== 'Active') {
                $stage = $m['last_activity'] < $loginDataBefore ? 'login_data' : null;
            } elseif ($m['last_activity'] < $flagBefore) {
                $stage = 'flagged';
            }
            if ($stage === null) {
                continue;
            }

            $m['stage']    = $stage;
            $m['blockers'] = in_array($stage, ['login_data', 'anonymize'], true) ? $this->blockers($memberId) : [];
            $m['keep_until'] = $this->financialKeepUntil($m['last_transaction']);
            $rows[] = $m;
        }

        return $rows;
    }

    /** Last day the member's financial records (and the name on them) must be kept. */
    public function financialKeepUntil(?string $lastTransaction): string
    {
        $year = (int) date('Y', strtotime($lastTransaction ?: date('Y-m-d', $this->now)));

        return ($year + $this->config->financialYears) . '-12-31';
    }

    private function financialPeriodEnded(?string $lastTransaction): bool
    {
        return date('Y-m-d', $this->now) > $this->financialKeepUntil($lastTransaction);
    }

    private function member(int $memberId): ?array
    {
        return $this->membersWithActivity()->where('m.member_id', $memberId)->get()->getRowArray();
    }

    // =========================================================================
    // Manual steps (super administrator)
    // =========================================================================

    /**
     * Replace a deactivated member's username and email, make the password
     * unusable, and delete their notifications and waiting requests. The name,
     * member number and financial records stay for the financial retention
     * period. Returns null on success or the reason it was refused.
     */
    public function removeLoginData(int $memberId): ?string
    {
        $m = $this->member($memberId);
        if (! $m) {
            return 'Member not found.';
        }
        if ($m['login_data_removed_at'] !== null) {
            return 'Sign-in data was already removed.';
        }
        if ($m['membership_status'] === 'Active') {
            return 'Deactivate the member first.';
        }
        if ($m['last_activity'] >= $this->ago("{$this->config->inactiveLoginDataMonths} months")) {
            return "The member has been inactive for less than {$this->config->inactiveLoginDataMonths} months.";
        }
        if ($blockers = $this->blockers($memberId)) {
            return 'Not yet: the member ' . implode(' and ', $blockers) . '.';
        }

        $db     = $this->db();
        $userId = (int) $m['user_id'];
        $alias  = 'former-' . $userId;

        $db->transStart();
        $db->table('users')->where('user_id', $userId)->update([
            'username'         => $alias,
            'email'            => $alias . '@removed.invalid',
            'password_hash'    => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'account_status'   => 'Inactive',
            'last_activity_at' => null,
        ]);
        if (AuditLog::available()) {
            // Earlier audit entries keep the action, but no longer the person's username.
            $db->table('audit_logs')->where('user_id', $userId)->update(['username' => $alias]);
        }
        $db->table('notifications')->where('member_id', $memberId)->delete();
        if ($db->tableExists('account_requests')) {
            $db->table('account_requests')->where('member_id', $memberId)->where('status', 'Pending')->delete();
        }
        $db->table('members')->where('member_id', $memberId)->update([
            'login_data_removed_at' => date('Y-m-d H:i:s', $this->now),
            'retention_reviewed_at' => date('Y-m-d H:i:s', $this->now),
        ]);
        $db->transComplete();

        return $db->transStatus() ? null : 'The database refused the change. Nothing was removed.';
    }

    /**
     * After the financial retention period: replace the member's name with
     * "Former member" and clear the free-text reasons on their accounts and
     * requests. Member and account numbers stay so the totals and the ledger
     * still add up. Returns null on success or the reason it was refused.
     */
    public function anonymize(int $memberId): ?string
    {
        $m = $this->member($memberId);
        if (! $m) {
            return 'Member not found.';
        }
        if ($m['anonymized_at'] !== null) {
            return 'This member is already anonymized.';
        }
        if ($m['login_data_removed_at'] === null) {
            return 'Remove the sign-in data first.';
        }
        if (! $this->financialPeriodEnded($m['last_transaction'])) {
            return 'The financial records must be kept until ' . $this->financialKeepUntil($m['last_transaction']) . '.';
        }
        if ($blockers = $this->blockers($memberId)) {
            return 'Not yet: the member ' . implode(' and ', $blockers) . '.';
        }

        $db = $this->db();
        $db->transStart();
        $db->table('members')->where('member_id', $memberId)->update([
            'first_name'            => 'Former',
            'last_name'             => 'member',
            'anonymized_at'         => date('Y-m-d H:i:s', $this->now),
            'retention_reviewed_at' => date('Y-m-d H:i:s', $this->now),
        ]);
        $db->table('cooperative_accounts')->where('member_id', $memberId)->update(['deactivation_reason' => null]);
        if ($db->tableExists('account_requests')) {
            $db->table('account_requests')->where('member_id', $memberId)->update(['reason' => null]);
        }
        $db->transComplete();

        return $db->transStatus() ? null : 'The database refused the change. Nothing was anonymized.';
    }

    /**
     * Place or release a legal hold: while it is set, the review neither
     * deactivates the member nor allows removing or anonymizing their data.
     */
    public function setHold(int $memberId, bool $hold, string $reason = ''): ?string
    {
        if (! $this->db()->table('members')->where('member_id', $memberId)->countAllResults()) {
            return 'Member not found.';
        }
        if ($hold && trim($reason) === '') {
            return 'Give the reason for the hold (for example, an unresolved dispute or an audit).';
        }

        $this->db()->table('members')->where('member_id', $memberId)->update([
            'legal_hold'        => $hold ? 1 : 0,
            'legal_hold_reason' => $hold ? mb_substr(trim($reason), 0, 255) : null,
        ]);

        return null;
    }

    /** Members currently on hold, for the hold form's list. */
    public function holds(): array
    {
        return $this->db()->table('members')
            ->select('member_id, member_number, first_name, last_name, legal_hold_reason')
            ->where('legal_hold', 1)->orderBy('member_number')->get()->getResultArray();
    }

    /** The last review run (from the audit log), or null. */
    public static function lastRun(): ?array
    {
        if (! AuditLog::available()) {
            return null;
        }

        return db_connect()->table('audit_logs')->select('created_at, username, details')
            ->where('action', 'Ran data retention review')->orderBy('audit_id', 'DESC')->limit(1)
            ->get()->getRowArray();
    }

    /**
     * Record that a login was used today. Called at sign-in and, once a day
     * per session, on later requests (AuthFilter).
     */
    public static function touch(int $userId): void
    {
        if ($userId <= 0 || ! self::available()) {
            return;
        }
        try {
            db_connect()->table('users')->where('user_id', $userId)->update(['last_activity_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            log_message('error', 'RetentionService::touch failed: ' . $e->getMessage());
        }
    }
}
