<?php

namespace App\Services;

/**
 * LoanService — installment calculation, payment processing, and loan-limit checks.
 */
class LoanService
{
    // -------------------------------------------------------------------------
    // Installment calculation
    // -------------------------------------------------------------------------

    /**
     * Generate an installment schedule for a loan.
     *
     * Interest is collected entirely in the first installment (front-loaded).
     * Remaining installments carry only the principal split evenly.
     *
     * @param  array $loan {
     *   loan_amount      : float,
     *   interest_rate    : float  (percentage, e.g. 5 = 5 %),
     *   loan_term_months : int,
     *   release_date     : string (Y-m-d)
     * }
     * @return array  Array of installment rows:
     *   [installment_number, due_date, principal_amount, interest_amount,
     *    amount_due, remaining_balance (= unpaid part, initially amount_due)]
     */
    public function calculateInstallments(array $loan): array
    {
        $loanAmount     = (float) $loan['loan_amount'];
        $interestRate   = (float) $loan['interest_rate'];
        $termMonths     = (int)   $loan['loan_term_months'];
        $releaseDate    = $loan['release_date'];

        if ($termMonths < 1) {
            return [];
        }

        $interestAmount    = round($loanAmount * ($interestRate / 100), 2);
        $principalPerMonth = round($loanAmount / $termMonths, 2);

        // Adjust last installment for rounding remainder
        $totalPrincipal = $principalPerMonth * $termMonths;
        $roundingAdj    = round($loanAmount - $totalPrincipal, 2);

        $installments     = [];
        $baseDateTs       = strtotime($releaseDate);

        for ($i = 1; $i <= $termMonths; $i++) {
            $dueTs      = strtotime("+{$i} month", $baseDateTs);
            $dueDate    = date('Y-m-d', $dueTs);

            // Principal: last installment absorbs rounding diff
            $principal = ($i === $termMonths)
                ? round($principalPerMonth + $roundingAdj, 2)
                : $principalPerMonth;

            // Interest only in the first installment
            $interest = ($i === 1) ? $interestAmount : 0.00;

            $amountDue        = round($principal + $interest, 2);

            $installments[] = [
                'installment_number' => $i,
                'due_date'           => $dueDate,
                'principal_amount'   => $principal,
                'interest_amount'    => $interest,
                'amount_due'         => $amountDue,
                'remaining_balance'  => $amountDue,
            ];
        }

        return $installments;
    }

    // -------------------------------------------------------------------------
    // Payments (month by month, with carry-over)
    // -------------------------------------------------------------------------
    //
    // amount_due   = the regular monthly amount (never changed)
    // carried_over = unpaid amount moved in from the previous month
    // Amount due for a month = amount_due + carried_over.
    // When a month is underpaid, it is closed as 'Partial' and the unpaid part
    // is added to the next month's carried_over. On the last month the unpaid
    // part simply stays open. Extra money pays the following months ahead.

    /**
     * Installment schedule with everything the screens need.
     *
     * @return array{rows: list<array>, summary: array}
     */
    public function schedule(int $loanId): array
    {
        $db   = db_connect();
        $rows = $db->table('loan_installments')
                   ->where('loan_id', $loanId)
                   ->orderBy('installment_number', 'ASC')
                   ->get()->getResultArray();

        $pending = $db->table('loan_payments')
                      ->where('loan_id', $loanId)
                      ->where('payment_status', 'Pending')
                      ->countAllResults() > 0;

        $today      = date('Y-m-d');
        $openFound  = false;
        $dueNow     = 0.0;
        $balance    = 0.0;
        $next       = null;
        $count      = count($rows);

        foreach ($rows as $i => &$row) {
            $regular = (float) $row['amount_due'];
            $carried = (float) ($row['carried_over'] ?? 0);
            $total   = round($regular + $carried, 2);
            $paid    = (float) $row['amount_paid'];
            $isOpen  = ! in_array($row['payment_status'], ['Paid', 'Partial'], true);
            $owed    = $isOpen ? max(0.0, round($total - $paid, 2)) : 0.0;

            $row['regular']    = $regular;
            $row['carried']    = $carried;
            $row['total_due']  = $total;
            $row['owed']       = $owed;
            $row['moved']      = $row['payment_status'] === 'Partial' ? max(0.0, round($total - $paid, 2)) : 0.0;
            $row['is_open']    = $isOpen && $owed > 0;
            $row['is_current'] = false;
            $row['next_month'] = $rows[$i + 1]['installment_number'] ?? null;
            $row['is_last']    = $i === $count - 1;

            if ($row['payment_status'] === 'Paid') {
                $row['label'] = 'Paid';
                $row['badge'] = 'bg-success';
            } elseif ($row['payment_status'] === 'Partial') {
                $row['label'] = 'Partially paid — ₱' . number_format($row['moved'], 2) . ' moved to Month ' . $row['next_month'];
                $row['badge'] = 'bg-info text-dark';
            } elseif ($row['due_date'] < $today) {
                $row['label'] = 'Late';
                $row['badge'] = 'bg-danger';
            } elseif (! $openFound) {
                $row['label'] = 'Due';
                $row['badge'] = 'bg-warning text-dark';
            } else {
                $row['label'] = 'Upcoming';
                $row['badge'] = 'bg-secondary';
            }

            if ($row['is_open']) {
                $balance += $owed;
                if (! $openFound) {
                    $openFound         = true;
                    $row['is_current'] = true;
                    $next              = $row;
                }
                if ($row['due_date'] < $today) {
                    $dueNow += $owed;
                }
            }
        }
        unset($row);

        if ($next && $dueNow <= 0) {
            $dueNow = $next['owed'];
        }

        return [
            'rows'    => $rows,
            'summary' => [
                'due_now'        => round($dueNow, 2),
                'next_due_date'  => $next['due_date'] ?? null,
                'current'        => $next,
                'balance'        => round($balance, 2),
                'total_payable'  => round(array_sum(array_column($rows, 'regular')), 2),
                'total_paid'     => round(array_sum(array_column($rows, 'amount_paid')), 2),
                'has_pending'    => $pending,
            ],
        ];
    }

    /**
     * Check a payment before it is saved. Members pay the oldest unpaid month;
     * any amount above zero is allowed, up to the whole remaining balance.
     *
     * @return ?string Error message, or null if the payment is valid.
     */
    public function validatePayment(int $loanId, float $amount, int $installmentId): ?string
    {
        $schedule = $this->schedule($loanId);
        $summary  = $schedule['summary'];
        $amount   = round($amount, 2);

        if ($summary['has_pending']) {
            return 'You already have a payment waiting for approval on this loan.';
        }
        if ($summary['current'] === null) {
            return 'This loan has nothing left to pay.';
        }
        if ((int) $summary['current']['installment_id'] !== $installmentId) {
            return "Please pay Month {$summary['current']['installment_number']} first; it is the oldest unpaid month.";
        }
        if ($amount <= 0) {
            return 'Payment amount must be greater than zero.';
        }
        if ($amount > $summary['balance']) {
            return 'The amount is more than your remaining loan balance (PHP ' . number_format($summary['balance'], 2) . ').';
        }

        return null;
    }

    /**
     * Apply an approved payment, starting at the month it was made for.
     * Fully covered months become Paid. If the month being paid is underpaid
     * it becomes Partial and its unpaid part moves to the next month; extra
     * money pays later months ahead, which stay open until fully paid.
     */
    public function applyPayment(int $loanId, int $startInstallmentId, float $amount, string $paymentDate): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $rows = $db->table('loan_installments')
                   ->where('loan_id', $loanId)
                   ->orderBy('installment_number', 'ASC')
                   ->get()->getResultArray();

        $startNumber = null;
        foreach ($rows as $r) {
            if ((int) $r['installment_id'] === $startInstallmentId) {
                $startNumber = (int) $r['installment_number'];
            }
        }

        $open = array_values(array_filter($rows, fn($r) =>
            ! in_array($r['payment_status'], ['Paid', 'Partial'], true)
            && ($startNumber === null || (int) $r['installment_number'] >= $startNumber)));

        $remaining = round($amount, 2);

        foreach ($open as $i => $row) {
            if ($remaining <= 0) {
                break;
            }

            $total = round((float) $row['amount_due'] + (float) $row['carried_over'], 2);
            $paid  = (float) $row['amount_paid'];
            $apply = min(round($total - $paid, 2), $remaining);
            $paid  = round($paid + $apply, 2);
            $remaining = round($remaining - $apply, 2);

            $update = ['amount_paid' => $paid, 'payment_date' => $paymentDate, 'updated_at' => $now];

            if ($paid >= $total) {
                $update['payment_status']    = 'Paid';
                $update['remaining_balance'] = 0.00;
            } elseif ($i === 0 && isset($open[$i + 1])) {
                // Only the month being paid carries its shortfall forward;
                // extra money that reaches a later month just pays it ahead.
                $shortfall = round($total - $paid, 2);
                $db->table('loan_installments')
                   ->where('installment_id', $open[$i + 1]['installment_id'])
                   ->update([
                       'carried_over'      => round((float) $open[$i + 1]['carried_over'] + $shortfall, 2),
                       'remaining_balance' => round((float) $open[$i + 1]['amount_due'] + (float) $open[$i + 1]['carried_over'] + $shortfall - (float) $open[$i + 1]['amount_paid'], 2),
                       'updated_at'        => $now,
                   ]);
                $update['payment_status']    = 'Partial';
                $update['remaining_balance'] = 0.00;
            } else {
                $update['remaining_balance'] = round($total - $paid, 2);
            }

            $db->table('loan_installments')
               ->where('installment_id', $row['installment_id'])
               ->update($update);
        }

        $unpaid = $db->table('loan_installments')
                     ->where('loan_id', $loanId)
                     ->whereNotIn('payment_status', ['Paid', 'Partial'])
                     ->countAllResults();

        if ($unpaid === 0) {
            $db->table('loans')
               ->where('loan_id', $loanId)
               ->update(['loan_status' => 'Paid', 'updated_at' => $now]);
        }
    }

    // -------------------------------------------------------------------------
    // Loan limit
    // -------------------------------------------------------------------------

    /**
     * Compute the maximum loan amount a member's cooperative account may apply for.
     * Maximum = 2 × total approved contributions for that account.
     *
     * @param  int   $accountId
     * @return float
     */
    public function checkMaxLoanLimit(int $accountId): float
    {
        $db = db_connect();

        $row = $db->table('financial_records')
                  ->selectSum('amount', 'total_contributions')
                  ->where('account_id', $accountId)
                  ->where('record_type', 'Contribution')
                  ->where('approval_status', 'Approved')
                  ->get()
                  ->getRowArray();

        $totalContributions = ($row && $row['total_contributions'] !== null)
            ? (float) $row['total_contributions']
            : 0.0;

        return round($totalContributions * 2, 2);
    }
}
