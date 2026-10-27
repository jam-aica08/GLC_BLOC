<?php

namespace App\Services;

/**
 * What each account holds in contributions, after withdrawals and loan
 * offsets, from the financial records:
 *
 *   contributed = approved Contribution records
 *   withdrawn   = approved Withdrawal records
 *   offset      = approved Loan Offset records (contributions applied to an unpaid loan)
 *   balance     = contributed − withdrawn − offset
 *   available   = balance − withdrawals still waiting for approval
 *
 * An account's contribution standing (dues, joining) is still counted from
 * contributions paid; this balance is the money the member can withdraw and
 * the base of the loan limit.
 */
class ContributionBalance
{
    /** Record types that take money out of an account's contributions. */
    public const DEDUCTIONS = ['Withdrawal', 'Loan Offset'];

    /** Loan statuses that count as an outstanding loan (an obligation not yet settled). */
    public const OUTSTANDING_LOAN = ['Pending', 'Approved', 'Active', 'Defaulted'];

    /**
     * @param list<int> $accountIds
     *
     * @return array<int, array{contributed: float, withdrawn: float, offset: float, pending: float, balance: float, available: float}>
     */
    public static function forAccounts(array $accountIds): array
    {
        $ids    = array_values(array_unique(array_map('intval', $accountIds)));
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = ['contributed' => 0.0, 'withdrawn' => 0.0, 'offset' => 0.0, 'pending' => 0.0, 'balance' => 0.0, 'available' => 0.0];
        }
        if ($ids === []) {
            return $result;
        }

        $rows = db_connect()->table('financial_records')
            ->select('account_id, record_type, approval_status, SUM(amount) AS total')
            ->whereIn('account_id', $ids)
            ->whereIn('record_type', ['Contribution', 'Withdrawal', 'Loan Offset'])
            ->whereIn('approval_status', ['Approved', 'Pending'])
            ->groupBy(['account_id', 'record_type', 'approval_status'])
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $acc   = &$result[(int) $r['account_id']];
            $total = (float) $r['total'];
            if ($r['approval_status'] === 'Approved') {
                match ($r['record_type']) {
                    'Contribution' => $acc['contributed'] += $total,
                    'Withdrawal'   => $acc['withdrawn'] += $total,
                    'Loan Offset'  => $acc['offset'] += $total,
                };
            } elseif ($r['record_type'] === 'Withdrawal') {
                $acc['pending'] += $total;
            }
            unset($acc);
        }

        foreach ($result as &$acc) {
            $acc['balance']   = round(max(0.0, $acc['contributed'] - $acc['withdrawn'] - $acc['offset']), 2);
            $acc['available'] = round(max(0.0, $acc['balance'] - $acc['pending']), 2);
            foreach (['contributed', 'withdrawn', 'offset', 'pending'] as $k) {
                $acc[$k] = round($acc[$k], 2);
            }
        }
        unset($acc);

        return $result;
    }

    public static function forAccount(int $accountId): array
    {
        return self::forAccounts([$accountId])[$accountId];
    }

    /**
     * The member's loans that are not settled: applied for, approved, being
     * repaid or defaulted, on any of their accounts.
     *
     * @return list<array{loan_id: int, loan_status: string, account_number: string}>
     */
    public static function outstandingLoans(int $memberId): array
    {
        return db_connect()->table('loans l')
            ->select('l.loan_id, l.loan_status, ca.account_number')
            ->join('cooperative_accounts ca', 'ca.account_id = l.account_id')
            ->where('ca.member_id', $memberId)
            ->whereIn('l.loan_status', self::OUTSTANDING_LOAN)
            ->orderBy('l.loan_id')
            ->get()->getResultArray();
    }
}
