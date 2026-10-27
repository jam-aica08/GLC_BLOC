<?php

namespace App\Services;

/**
 * Loan Offset Policy: when a member does not meet their loan repayments, an
 * administrator may settle the unpaid loan from the member's contributions.
 *
 * An offset is allowed only on a loan with a missed payment (a month past its
 * due date with money still owed, or a Defaulted loan) and no payment waiting
 * for approval. It is taken from the loan's own account first, then the
 * member's other accounts, never more than their available contributions or
 * the loan's balance.
 *
 * It is recorded as:
 *  - an approved loan payment (method "Contribution offset") applied to the
 *    loan's months, oldest first, exactly like a member's payment, and
 *  - one approved 'Loan Offset' financial record per account it was taken
 *    from (reducing that account's contributions), each added to the ledger.
 * The administrator's confirmation is the authorization; it is audited and
 * the member is notified.
 */
class LoanOffsetService
{
    public const METHOD = 'Contribution offset';

    private function db()
    {
        return db_connect();
    }

    /**
     * What an offset could do for a loan right now.
     *
     * @return array{eligible: bool, reason: ?string, overdue: float, balance: float, available: float, max: float, sources: list<array>}
     */
    public function preview(int $loanId): array
    {
        $db   = $this->db();
        $loan = $db->table('loans l')->select('l.*, ca.member_id, ca.account_number')
            ->join('cooperative_accounts ca', 'ca.account_id = l.account_id')
            ->where('l.loan_id', $loanId)->get()->getRowArray();
        $out = ['eligible' => false, 'reason' => null, 'overdue' => 0.0, 'balance' => 0.0, 'available' => 0.0, 'max' => 0.0, 'sources' => [], 'loan' => $loan];
        if (! $loan) {
            $out['reason'] = 'Loan not found.';
            return $out;
        }
        if (! in_array($loan['loan_status'], ['Active', 'Defaulted'], true)) {
            $out['reason'] = "Loan #{$loanId} is {$loan['loan_status']}; only a loan being repaid can be offset.";
            return $out;
        }

        // Overdue = what is still unpaid from months whose due date has passed:
        // the owed amount of an open month that is late, plus the part of an
        // open month's carried-over amount (moved in from missed months) not yet
        // covered by payments to it.
        $schedule = (new LoanService())->schedule($loanId);
        $today    = date('Y-m-d');
        foreach ($schedule['rows'] as $row) {
            if (! $row['is_open']) {
                continue;
            }
            $out['overdue'] += $row['due_date'] < $today
                ? $row['owed']
                : max(0.0, min($row['owed'], (float) $row['carried'] - (float) $row['amount_paid']));
        }
        $out['overdue'] = round($out['overdue'], 2);
        $out['balance'] = $schedule['summary']['balance'];

        // The loan's account first, then the member's other accounts.
        $accounts = $db->table('cooperative_accounts')->select('account_id, account_number')
            ->where('member_id', (int) $loan['member_id'])
            ->orderBy('account_id = ' . (int) $loan['account_id'], 'DESC', false)->orderBy('account_id')
            ->get()->getResultArray();
        $balances = ContributionBalance::forAccounts(array_column($accounts, 'account_id'));
        foreach ($accounts as $a) {
            $available = $balances[(int) $a['account_id']]['available'];
            if ($available > 0) {
                $out['sources'][] = ['account_id' => (int) $a['account_id'], 'account_number' => $a['account_number'], 'available' => $available];
                $out['available'] += $available;
            }
        }
        $out['available'] = round($out['available'], 2);
        $out['max']       = round(min($out['balance'], $out['available']), 2);

        if ($schedule['summary']['has_pending']) {
            $out['reason'] = 'A payment on this loan is waiting for approval. Approve or reject it first.';
        } elseif ($out['overdue'] <= 0 && $loan['loan_status'] !== 'Defaulted') {
            $out['reason'] = 'The member has not missed a payment on this loan, so no offset is allowed.';
        } elseif ($out['max'] <= 0) {
            $out['reason'] = 'The member has no available contributions to offset.';
        } else {
            $out['eligible'] = true;
        }

        return $out;
    }

    /**
     * Apply an offset of $amount (null = as much as allowed). Returns null on
     * success or the reason it was refused; nothing is changed when refused.
     */
    public function apply(int $loanId, ?float $amount, int $adminId): ?string
    {
        $db = $this->db();
        $db->transBegin();
        try {
            // Lock the loan so a member's payment or a second offset cannot run alongside.
            $db->query('SELECT loan_id FROM loans WHERE loan_id = ? FOR UPDATE', [$loanId]);
            $p = $this->preview($loanId);
            if (! $p['eligible']) {
                $db->transRollback();
                return $p['reason'];
            }
            $amount = round($amount ?? $p['max'], 2);
            if ($amount <= 0 || $amount > $p['max'] + 0.004) {
                $db->transRollback();
                return 'Enter an amount from PHP 0.01 to PHP ' . number_format($p['max'], 2) . '.';
            }

            $now   = date('Y-m-d H:i:s');
            $today = date('Y-m-d');

            // Months to pay, oldest first.
            $allocations = [];
            $left        = $amount;
            foreach ((new LoanService())->schedule($loanId)['rows'] as $row) {
                if ($left <= 0) {
                    break;
                }
                if ($row['is_open']) {
                    $take = min($row['owed'], $left);
                    $allocations[(int) $row['installment_id']] = $take;
                    $left = round($left - $take, 2);
                }
            }

            // Accounts to take it from.
            $records = [];
            $left    = $amount;
            foreach ($p['sources'] as $s) {
                if ($left <= 0) {
                    break;
                }
                $take = round(min($s['available'], $left), 2);
                $left = round($left - $take, 2);
                $db->table('financial_records')->insert([
                    'account_id'          => $s['account_id'],
                    'record_type'         => 'Loan Offset',
                    'transaction_date'    => $today,
                    'amount'              => $take,
                    'approval_status'     => 'Approved',
                    'processed_by'        => $adminId,
                    'first_approved_by'   => $adminId,
                    'first_approval_date' => $now,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
                $records[] = ['id' => (int) $db->insertID(), 'account_number' => $s['account_number'], 'amount' => $take];
            }

            $db->table('loan_payments')->insert([
                'loan_id'             => $loanId,
                'payment_date'        => $today,
                'amount_paid'         => $amount,
                'payment_method'      => self::METHOD,
                'financial_record_id' => $records[0]['id'],
                'principal_amount'    => $amount,
                'interest_amount'     => 0.00,
                'payment_status'      => 'Approved',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $paymentId = (int) $db->insertID();
            foreach ($allocations as $installmentId => $applied) {
                $db->table('loan_payment_installments')->insert(['payment_id' => $paymentId, 'installment_id' => $installmentId, 'amount_applied' => $applied]);
            }
            $db->table('financial_records')->whereIn('financial_record_id', array_column($records, 'id'))->update(['reference_id' => $paymentId]);

            (new LoanService())->applyPayment($loanId, $allocations, $today);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('The database refused the offset.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'LoanOffsetService::apply #' . $loanId . ' failed: ' . $e->getMessage());
            return 'Could not apply the offset. Nothing was changed.';
        }

        $ledger = new LedgerService();
        foreach ($records as $r) {
            try {
                $ledger->createEntry($r['id'], $db->table('financial_records')->where('financial_record_id', $r['id'])->get()->getRowArray());
            } catch (\Throwable $e) {
                log_message('error', 'Ledger entry failed for loan offset record #' . $r['id'] . ': ' . $e->getMessage());
            }
        }

        $from = implode(', ', array_map(fn($r) => $r['account_number'] . ' (PHP ' . number_format($r['amount'], 2) . ')', $records));
        Notifier::toMember((int) $p['loan']['member_id'], 'loan', 'Loan offset applied',
            'Because payments on loan #' . $loanId . ' were missed, PHP ' . number_format($amount, 2)
            . " of your contributions was applied to it under the cooperative's loan offset policy, from: {$from}.",
            'member/loans', 'loan-offset-' . $paymentId);

        return null;
    }
}
