<?php

namespace App\Services;

use App\Libraries\SchemaCache;
use App\Models\CooperativeAccountModel;

/**
 * Deactivating and reactivating one cooperative account of a member, without
 * touching the member's other accounts or their login.
 *
 * An Inactive account takes no new contributions or loans; it stays eligible
 * for dividends once its joining contributions are complete (see
 * DividendService), and every record it already has is kept. Its available
 * contributions can still be withdrawn. It cannot be deactivated
 * while it has an open loan or anything waiting for approval, so no debt or
 * payment is stranded. Reactivating returns it to Pending, and it becomes
 * Active again once its joining contributions are paid, as for a new account.
 */
class AccountStatusService
{
    private function db()
    {
        return db_connect();
    }

    /** False until "Apply Database Updates" has added the deactivation columns. */
    public static function available(): bool
    {
        try {
            return SchemaCache::passes('account_deactivation_columns',
                static fn () => db_connect()->fieldExists('deactivated_at', 'cooperative_accounts'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function find(int $accountId): ?array
    {
        return $this->db()->table('cooperative_accounts ca')
            ->select('ca.*, m.first_name, m.last_name, m.membership_status')
            ->join('members m', 'm.member_id = ca.member_id')
            ->where('ca.account_id', $accountId)
            ->get()->getRowArray();
    }

    /**
     * Why this account cannot be deactivated right now (empty when it can).
     *
     * @return list<string>
     */
    public function blockers(int $accountId): array
    {
        return $this->blockersFor([$accountId])[$accountId];
    }

    /**
     * Blockers for several accounts at once (account_id => list), for the
     * Members page, which shows whether each account can be deactivated.
     *
     * @param list<int> $accountIds
     *
     * @return array<int, list<string>>
     */
    public function blockersFor(array $accountIds): array
    {
        $ids    = array_map('intval', $accountIds);
        $result = array_fill_keys($ids, []);
        if ($ids === []) {
            return $result;
        }
        $db = $this->db();

        // One grouped query per kind of blocker, not four per account.
        $checks = [
            'it has a loan that is not fully paid or still waiting for approval' => $db->table('loans')->select('account_id')
                ->whereIn('account_id', $ids)->whereIn('loan_status', ['Pending', 'Approved', 'Active']),
            'it has a contribution waiting for approval' => $db->table('contributions')->select('account_id')
                ->whereIn('account_id', $ids)->where('payment_status', 'Pending'),
            'it has a loan payment waiting for approval' => $db->table('loan_payments lp')->select('l.account_id')
                ->join('loans l', 'l.loan_id = lp.loan_id')->whereIn('l.account_id', $ids)->where('lp.payment_status', 'Pending'),
            'it is part of a dividend distribution waiting for approval' => $db->table('dividends')->select('account_id')
                ->whereIn('account_id', $ids)->where('dividend_status', 'Pending'),
        ];
        foreach ($checks as $reason => $query) {
            foreach (array_unique(array_column($query->distinct()->get()->getResultArray(), 'account_id')) as $id) {
                $result[(int) $id][] = $reason;
            }
        }

        return $result;
    }

    /**
     * Returns null on success or the reason it was refused.
     */
    public function deactivate(int $accountId, int $actorUserId, string $reason = ''): ?string
    {
        if (! self::available()) {
            return 'Apply the database updates in Settings first.';
        }
        $account = $this->find($accountId);
        if (! $account) {
            return 'Account not found.';
        }
        if (in_array($account['account_status'], ['Inactive', 'Closed'], true)) {
            return "Account {$account['account_number']} is already {$account['account_status']}.";
        }
        if ($blockers = $this->blockers($accountId)) {
            return "Account {$account['account_number']} cannot be deactivated yet: " . implode('; ', $blockers) . '.';
        }

        $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
        $this->db()->table('cooperative_accounts')->where('account_id', $accountId)->update([
            'account_status'      => 'Inactive',
            'deactivated_at'      => date('Y-m-d H:i:s'),
            'deactivated_by'      => $actorUserId ?: null,
            'deactivation_reason' => $reason === '' ? null : $reason,
        ]);
        // Its "activate this account" reminder no longer applies.
        if (Notifier::available()) {
            $this->db()->table('notifications')->where('member_id', (int) $account['member_id'])
                 ->like('event_key', 'activate-' . $accountId . '-', 'after')->delete();
        }

        Notifier::toMember((int) $account['member_id'], 'account', 'Account ' . $account['account_number'] . ' deactivated',
            "Your {$account['account_type']} account {$account['account_number']} was deactivated"
            . ($reason !== '' ? " ({$reason})" : '') . '. It takes no new contributions or loans; it stays eligible for dividends and its records are kept.',
            'member/profile', 'account-deactivated-' . $accountId . '-' . time());

        return null;
    }

    /**
     * Returns null on success or the reason it was refused.
     */
    public function reactivate(int $accountId): ?string
    {
        if (! self::available()) {
            return 'Apply the database updates in Settings first.';
        }
        $account = $this->find($accountId);
        if (! $account) {
            return 'Account not found.';
        }
        if ($account['account_status'] !== 'Inactive') {
            return "Account {$account['account_number']} is not deactivated.";
        }
        if ($account['membership_status'] !== 'Active') {
            return 'Reactivate the member first.';
        }

        // Pending, then Active at once if its joining contributions are already paid.
        $this->db()->table('cooperative_accounts')->where('account_id', $accountId)->update([
            'account_status'      => 'Pending',
            'deactivated_at'      => null,
            'deactivated_by'      => null,
            'deactivation_reason' => null,
        ]);
        model(CooperativeAccountModel::class)->syncStatuses();
        $status = $this->find($accountId)['account_status'];

        Notifier::toMember((int) $account['member_id'], 'account', 'Account ' . $account['account_number'] . ' reactivated',
            "Your {$account['account_type']} account {$account['account_number']} was reactivated"
            . ($status === 'Active' ? ' and is active.' : ' and becomes active once its joining contributions are paid.'),
            'member/profile', 'account-reactivated-' . $accountId . '-' . time());

        return null;
    }
}
