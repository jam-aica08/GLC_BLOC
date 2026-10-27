<?php

namespace App\Services;

use App\Libraries\SchemaCache;

/**
 * Withdrawals of contributions (Flexible Withdrawal Policy).
 *
 * A member asks to withdraw part or all of an account's available
 * contributions (ContributionBalance). The request is a Pending 'Withdrawal'
 * financial record that an administrator approves or rejects, like a
 * contribution; approval adds it to the blockchain ledger.
 *
 * Restriction: while the member has an outstanding loan (applied for,
 * approved, being repaid or defaulted) on any account, no withdrawal can be
 * requested or approved. Each account has at most one request waiting.
 */
class WithdrawalService
{
    public const METHODS = ['Cash', 'GCash', 'Bank transfer'];

    private function db()
    {
        return db_connect();
    }

    /** False until "Apply Database Updates" has created the withdrawals table. */
    public static function available(): bool
    {
        try {
            return SchemaCache::passes('table_withdrawals', static fn () => db_connect()->tableExists('withdrawals'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Why the member cannot withdraw right now (empty when they can).
     *
     * @return list<string>
     */
    public function memberBlockers(int $memberId): array
    {
        $loans = ContributionBalance::outstandingLoans($memberId);
        if ($loans === []) {
            return [];
        }
        $list = implode(', ', array_map(fn($l) => "#{$l['loan_id']} ({$l['loan_status']})", $loans));

        return ["you have an outstanding loan ({$list}); withdrawals are allowed once every loan is fully paid"];
    }

    /**
     * Check a request against the policy. Returns null or the reason it is refused.
     */
    public function check(int $memberId, array $account, float $amount, ?int $ignoreWithdrawalId = null): ?string
    {
        if ($blockers = $this->memberBlockers($memberId)) {
            return 'You cannot withdraw contributions yet: ' . $blockers[0] . '.';
        }
        if ($account['account_status'] === 'Closed') {
            return "Account {$account['account_number']} is closed.";
        }
        if ($amount <= 0) {
            return 'Enter the amount to withdraw.';
        }

        $balance   = ContributionBalance::forAccount((int) $account['account_id']);
        $available = $balance['available'];
        if ($ignoreWithdrawalId !== null) {
            // Approving a request: its own pending amount is part of what it may take.
            $own = $this->db()->table('withdrawals')->select('amount')->where('withdrawal_id', $ignoreWithdrawalId)->get()->getRowArray();
            $available = round($available + (float) ($own['amount'] ?? 0), 2);
        }
        if ($amount > $available + 0.004) {
            return "Account {$account['account_number']} has PHP " . number_format($available, 2) . ' available to withdraw.';
        }

        return null;
    }

    /**
     * A member asks to withdraw from one of their accounts. Returns the new
     * withdrawal's ID, or an error message.
     */
    public function request(int $memberId, int $accountId, float $amount, string $method, string $reason, int $userId): int|string
    {
        if (! self::available()) {
            return 'Withdrawals are not available yet. Please try again later.';
        }
        $amount  = round($amount, 2);
        $db      = $this->db();
        $account = $db->table('cooperative_accounts')->where('account_id', $accountId)->where('member_id', $memberId)->get()->getRowArray();
        if (! $account) {
            return 'Please choose one of your accounts.';
        }
        if (! in_array($method, self::METHODS, true)) {
            return 'Choose how you want to receive the money.';
        }
        if ($db->table('withdrawals')->where('account_id', $accountId)->where('status', 'Pending')->countAllResults() > 0) {
            return "You already have a withdrawal from account {$account['account_number']} waiting for approval.";
        }

        $db->transStart();
        // Lock the account so two quick requests cannot both pass the balance check.
        $db->query('SELECT account_id FROM cooperative_accounts WHERE account_id = ? FOR UPDATE', [$accountId]);
        if ($error = $this->check($memberId, $account, $amount)) {
            $db->transRollback();
            return $error;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('financial_records')->insert([
            'account_id'       => $accountId,
            'record_type'      => 'Withdrawal',
            'transaction_date' => date('Y-m-d'),
            'amount'           => $amount,
            'approval_status'  => 'Pending',
            'processed_by'     => $userId ?: null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $frId = (int) $db->insertID();

        $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
        $db->table('withdrawals')->insert([
            'account_id'          => $accountId,
            'amount'              => $amount,
            'method'              => $method,
            'reason'              => $reason === '' ? null : $reason,
            'status'              => 'Pending',
            'financial_record_id' => $frId,
            'requested_by'        => $userId ?: null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $withdrawalId = (int) $db->insertID();
        $db->table('financial_records')->where('financial_record_id', $frId)->update(['reference_id' => $withdrawalId]);
        $db->transComplete();

        if (! $db->transStatus()) {
            return 'Could not save the request. Please try again.';
        }

        return $withdrawalId;
    }

    /**
     * Called before an administrator approves a Withdrawal record: the policy
     * is checked again, since a loan may have been taken out or the balance
     * changed since the request.
     */
    public function checkBeforeApproval(int $financialRecordId): ?string
    {
        $w = $this->db()->table('withdrawals w')
            ->select('w.*, ca.member_id, ca.account_number, ca.account_status, ca.account_id')
            ->join('cooperative_accounts ca', 'ca.account_id = w.account_id')
            ->where('w.financial_record_id', $financialRecordId)
            ->get()->getRowArray();
        if (! $w) {
            return 'This withdrawal request no longer exists.';
        }
        if ($loans = ContributionBalance::outstandingLoans((int) $w['member_id'])) {
            return 'Cannot approve withdrawal #' . $w['withdrawal_id'] . ': the member has an outstanding loan ('
                . implode(', ', array_map(fn($l) => "#{$l['loan_id']} {$l['loan_status']}", $loans)) . '). Reject it, or approve it once the loan is fully paid.';
        }
        $error = $this->check((int) $w['member_id'], $w, (float) $w['amount'], (int) $w['withdrawal_id']);

        return $error === null ? null : 'Cannot approve withdrawal #' . $w['withdrawal_id'] . ': ' . $error;
    }

    /** Requests of one member, newest first. */
    public function forMember(int $memberId): array
    {
        if (! self::available()) {
            return [];
        }

        return $this->db()->table('withdrawals w')
            ->select('w.*, ca.account_number, ca.account_type')
            ->join('cooperative_accounts ca', 'ca.account_id = w.account_id')
            ->where('ca.member_id', $memberId)
            ->orderBy('w.withdrawal_id', 'DESC')
            ->get()->getResultArray();
    }

    /** Every request with its member, for the administrators' page. */
    public function query(string $status = '', string $search = '')
    {
        $q = $this->db()->table('withdrawals w')
            ->select("w.*, ca.account_number, ca.account_type, ca.member_id, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name,
                      fr.approval_status, COALESCE(u2.username, u1.username) AS approver_name, COALESCE(fr.second_approval_date, fr.first_approval_date) AS approved_at", false)
            ->join('cooperative_accounts ca', 'ca.account_id = w.account_id')
            ->join('members m', 'm.member_id = ca.member_id')
            ->join('financial_records fr', 'fr.financial_record_id = w.financial_record_id', 'left')
            ->join('users u1', 'u1.user_id = fr.first_approved_by', 'left')
            ->join('users u2', 'u2.user_id = fr.second_approved_by', 'left')
            ->orderBy("w.status = 'Pending'", 'DESC', false)
            ->orderBy('w.withdrawal_id', 'DESC');
        if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
            $q->where('w.status', $status);
        }
        if ($search !== '') {
            $q->groupStart()
              ->like("CONCAT(m.first_name, ' ', m.last_name)", $search, 'both', null, true)
              ->orLike('m.member_number', $search, 'both', null, true)
              ->orLike('ca.account_number', $search, 'both', null, true)
              ->groupEnd();
        }

        return $q;
    }

    public function pendingCount(): int
    {
        return self::available() ? $this->db()->table('withdrawals')->where('status', 'Pending')->countAllResults() : 0;
    }
}
