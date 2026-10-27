<?php

namespace App\Services;

use App\Libraries\SchemaCache;
use App\Models\CooperativeAccountModel;

/**
 * Deactivating and reactivating one cooperative account of a member, without
 * touching the member's other accounts or their login.
 *
 * An Inactive (deactivated) account is not closed: it gets no contribution
 * reminders and takes no new loans, but can still take contributions; its contributions
 * stay, and it is eligible for a year's dividends if it completed PHP 3,600
 * for that year (see DividendService). Every record it has is kept. Its available
 * contributions can still be withdrawn. It cannot be deactivated
 * while it has an open loan or anything waiting for approval, so no debt or
 * payment is stranded. Reactivating returns it to Pending until the monthly
 * contributions missed from the month it was deactivated up to the current
 * month are paid (Account Reactivation Policy); then it is Active again.
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
        // Deactivated again while still settling a reactivation: reactivation_from
        // is kept, so the months missed since the first stop stay owed.
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
            . ($reason !== '' ? " ({$reason})" : '') . '. It is not closed: you will not get contribution reminders for it and it takes no new loans, but you can still contribute to it, your contributions stay, and it can still receive dividends for a year in which it completed PHP 3,600.',
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

        // Account Reactivation Policy: the account is Pending until the monthly
        // contributions missed from the month it was deactivated up to this
        // month are paid; then syncStatuses() makes it Active again.
        $update = [
            'account_status'      => 'Pending',
            'deactivated_at'      => null,
            'deactivated_by'      => null,
            'deactivation_reason' => null,
        ];
        if (CooperativeAccountModel::reactivationAvailable()) {
            // From the month it stopped: an earlier unsettled stop if there is one, else this deactivation.
            $update['reactivation_from'] = ($account['reactivation_from'] ?? null) ?: date('Y-m', strtotime($account['deactivated_at'] ?: 'now'));
        }
        $this->db()->table('cooperative_accounts')->where('account_id', $accountId)->update($update);
        $accountModel = model(CooperativeAccountModel::class);
        $accountModel->syncStatuses();
        $fresh  = $this->find($accountId);
        $status = $fresh['account_status'];

        $owed = $accountModel->reactivationArrears($fresh);
        Notifier::toMember((int) $account['member_id'], 'account', 'Account ' . $account['account_number'] . ' reactivated',
            "Your {$account['account_type']} account {$account['account_number']} was reactivated"
            . match (true) {
                $status === 'Active' => ' and is active.',
                $owed > 0            => '. Pay the missed monthly contributions from ' . date('F Y', strtotime(($fresh['reactivation_from'] ?? date('Y-m')) . '-01'))
                                        . ' to ' . date('F Y') . ' (PHP ' . number_format($owed, 2) . ') to make it active again.',
                default              => ' and becomes active once its joining contributions are paid.',
            },
            'member/contributions', 'account-reactivated-' . $accountId . '-' . time());

        return null;
    }

    /** What a reactivated account still owes, and from which month (for messages). */
    public function arrears(int $accountId): array
    {
        $account = $this->find($accountId);

        return [
            'owed' => $account ? model(CooperativeAccountModel::class)->reactivationArrears($account) : 0.0,
            'from' => $account['reactivation_from'] ?? null,
        ];
    }
}
