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
        $releaseDay       = (int) date('j', $baseDateTs);

        for ($i = 1; $i <= $termMonths; $i++) {
            // Same day each month, or the month's last day when it is shorter.
            // ("+1 month" from 31 May would give 1 July and skip June.)
            $monthTs = strtotime("first day of +{$i} month", $baseDateTs);
            $dueDate = date('Y-m-', $monthTs) . sprintf('%02d', min($releaseDay, (int) date('t', $monthTs)));

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
    // Payments (month by month, with carry-over on the due date)
    // -------------------------------------------------------------------------
    //
    // amount_due   = the regular monthly amount (never changed)
    // carried_over = unpaid amount moved in from the previous month
    // Amount due for a month = amount_due + carried_over.
    //
    // Members can pay any amount towards any open month (oldest first), as many
    // times as they like, until its due date. After the due date, whatever is
    // still unpaid moves to the next month and the month is closed as
    // 'Partial'. The last month keeps its unpaid amount (shown as Late).

    private const CLOSED = ['Paid', 'Partial'];

    /**
     * Move unpaid amounts of past-due months into the next month. Skipped while
     * a payment is waiting for approval, so on-time payments are counted first.
     * $asOf (Y-m-d) replays history as of that day; it defaults to today.
     */
    public function rollOver(int $loanId, ?string $asOf = null): void
    {
        $db = db_connect();

        $pending = $db->table('loan_payments')
                      ->where('loan_id', $loanId)
                      ->where('payment_status', 'Pending')
                      ->countAllResults();
        if ($pending > 0) {
            return;
        }

        $rows = $db->table('loan_installments')
                   ->where('loan_id', $loanId)
                   ->orderBy('installment_number', 'ASC')
                   ->get()->getResultArray();

        $today = $asOf ?? date('Y-m-d');
        $now   = date('Y-m-d H:i:s');
        $last  = count($rows) - 1;

        // Read each month fresh from $rows: a month just given a carried amount
        // must pass all of it on when it is missed too. (foreach by value would
        // read a copy without it, losing the earlier months' unpaid amounts.)
        foreach (array_keys($rows) as $i) {
            $row = $rows[$i];
            if ($i === $last || in_array($row['payment_status'], self::CLOSED, true) || $row['due_date'] >= $today) {
                continue;
            }

            $total  = round((float) $row['amount_due'] + (float) $row['carried_over'], 2);
            $unpaid = round($total - (float) $row['amount_paid'], 2);
            if ($unpaid <= 0) {
                continue;
            }

            $next = &$rows[$i + 1];
            $next['carried_over'] = round((float) $next['carried_over'] + $unpaid, 2);

            $nextUpdate = [
                'carried_over'      => $next['carried_over'],
                'remaining_balance' => round((float) $next['amount_due'] + $next['carried_over'] - (float) $next['amount_paid'], 2),
                'updated_at'        => $now,
            ];
            if ($next['payment_status'] === 'Paid') {
                // It was paid ahead; the carried amount reopens it.
                $nextUpdate['payment_status'] = 'Upcoming';
                $next['payment_status']       = 'Upcoming';
            }
            $db->table('loan_installments')->where('installment_id', $next['installment_id'])->update($nextUpdate);
            $db->table('loan_installments')->where('installment_id', $row['installment_id'])->update([
                'payment_status'    => 'Partial',
                'remaining_balance' => 0.00,
                'updated_at'        => $now,
            ]);
            unset($next);
        }
    }

    /**
     * Installment schedule with everything the screens need.
     *
     * @return array{rows: list<array>, summary: array}
     */
    public function schedule(int $loanId): array
    {
        $this->rollOver($loanId);

        $db   = db_connect();
        $rows = $db->table('loan_installments')
                   ->where('loan_id', $loanId)
                   ->orderBy('installment_number', 'ASC')
                   ->get()->getResultArray();

        $pending = $db->table('loan_payments')
                      ->where('loan_id', $loanId)
                      ->where('payment_status', 'Pending')
                      ->countAllResults() > 0;

        $today     = date('Y-m-d');
        $openFound = false;
        $dueNow    = 0.0;
        $balance   = 0.0;
        $next      = null;

        foreach ($rows as $i => &$row) {
            $regular = (float) $row['amount_due'];
            $carried = (float) ($row['carried_over'] ?? 0);
            $total   = round($regular + $carried, 2);
            $paid    = (float) $row['amount_paid'];
            $closed  = in_array($row['payment_status'], self::CLOSED, true);
            $owed    = $closed ? 0.0 : max(0.0, round($total - $paid, 2));

            $row['regular']    = $regular;
            $row['carried']    = $carried;
            $row['total_due']  = $total;
            $row['owed']       = $owed;
            $row['moved']      = $row['payment_status'] === 'Partial' ? max(0.0, round($total - $paid, 2)) : 0.0;
            $row['is_open']    = $owed > 0;
            $row['is_current'] = false;
            $row['next_month'] = $rows[$i + 1]['installment_number'] ?? null;

            if ($row['payment_status'] === 'Paid' || (! $closed && $owed <= 0)) {
                $row['label'] = 'Paid';
                $row['badge'] = 'bg-success';
            } elseif ($row['payment_status'] === 'Partial') {
                $row['label'] = ($paid > 0 ? 'Partially paid' : 'Unpaid') . ' — ₱' . number_format($row['moved'], 2) . ' moved to Month ' . $row['next_month'];
                $row['badge'] = 'bg-info text-dark';
            } elseif ($row['due_date'] < $today) {
                $row['label'] = 'Late';
                $row['badge'] = 'bg-danger';
            } elseif (! $openFound) {
                $row['label'] = $paid > 0 ? 'Due — ₱' . number_format($paid, 2) . ' paid so far' : 'Due';
                $row['badge'] = 'bg-warning text-dark';
            } else {
                $row['label'] = $paid > 0 ? 'Paid ahead ₱' . number_format($paid, 2) : 'Upcoming';
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
                'due_now'       => round($dueNow, 2),
                'next_due_date' => $next['due_date'] ?? null,
                'current'       => $next,
                'balance'       => round($balance, 2),
                'total_payable' => round(array_sum(array_column($rows, 'regular')), 2),
                'total_paid'    => round(array_sum(array_column($rows, 'amount_paid')), 2),
                'has_pending'   => $pending,
            ],
        ];
    }

    /**
     * Check a payment before it is saved.
     *
     * @param  array<int, float> $amounts installment_id => amount to pay
     * @return ?string Error message, or null if the payment is valid.
     */
    public function validatePayment(int $loanId, array $amounts): ?string
    {
        $schedule = $this->schedule($loanId);

        if ($schedule['summary']['has_pending']) {
            return 'You already have a payment waiting for approval on this loan.';
        }
        if ($amounts === []) {
            return 'Please tick at least one month to pay.';
        }

        $open = array_values(array_filter($schedule['rows'], fn($r) => $r['is_open']));
        if ($open === []) {
            return 'This loan has nothing left to pay.';
        }

        $openIds = array_map(fn($r) => (int) $r['installment_id'], $open);
        foreach ($amounts as $id => $amount) {
            if (! in_array((int) $id, $openIds, true)) {
                return 'One of the selected months is already settled or does not belong to this loan.';
            }
        }

        $reachedEnd = false;
        foreach ($open as $row) {
            $id       = (int) $row['installment_id'];
            $selected = array_key_exists($id, $amounts);
            if ($selected && $reachedEnd) {
                return "Please also tick Month " . ($row['installment_number'] - 1) . "; months are paid in order.";
            }
            if (! $selected) {
                $reachedEnd = true;
                continue;
            }

            $amount = round((float) $amounts[$id], 2);
            if ($amount <= 0) {
                return "Enter an amount for Month {$row['installment_number']}.";
            }
            if ($amount > $row['owed']) {
                return "Month {$row['installment_number']} only has ₱" . number_format($row['owed'], 2) . ' left to pay.';
            }
        }

        return null;
    }

    /**
     * Apply an approved payment. Each amount goes to its month; if that month
     * was closed in the meantime (its balance moved on), the amount follows
     * to the next open month. A month becomes Paid once fully covered.
     *
     * @param array<int, float> $allocations installment_id => amount
     * @param ?string           $asOf        Roll over as of this day instead of today (imports).
     */
    public function applyPayment(int $loanId, array $allocations, string $paymentDate, ?string $asOf = null): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $rows = $db->table('loan_installments')
                   ->where('loan_id', $loanId)
                   ->orderBy('installment_number', 'ASC')
                   ->get()->getResultArray();

        $index = [];
        foreach ($rows as $i => $r) {
            $index[(int) $r['installment_id']] = $i;
        }

        uksort($allocations, fn($a, $b) => ($index[$a] ?? PHP_INT_MAX) <=> ($index[$b] ?? PHP_INT_MAX));

        foreach ($allocations as $id => $amount) {
            $remaining = round((float) $amount, 2);
            for ($i = $index[$id] ?? count($rows); $i < count($rows) && $remaining > 0; $i++) {
                if (in_array($rows[$i]['payment_status'], self::CLOSED, true)) {
                    continue;
                }
                $total = round((float) $rows[$i]['amount_due'] + (float) $rows[$i]['carried_over'], 2);
                $apply = min(round($total - (float) $rows[$i]['amount_paid'], 2), $remaining);
                if ($apply <= 0) {
                    continue;
                }
                $remaining = round($remaining - $apply, 2);
                $rows[$i]['amount_paid'] = round((float) $rows[$i]['amount_paid'] + $apply, 2);

                $update = [
                    'amount_paid'       => $rows[$i]['amount_paid'],
                    'remaining_balance' => round($total - $rows[$i]['amount_paid'], 2),
                    'payment_date'      => $paymentDate,
                    'updated_at'        => $now,
                ];
                if ($rows[$i]['amount_paid'] >= $total) {
                    $update['payment_status']   = 'Paid';
                    $rows[$i]['payment_status'] = 'Paid';
                }
                $db->table('loan_installments')->where('installment_id', $rows[$i]['installment_id'])->update($update);
            }
        }

        $this->rollOver($loanId, $asOf);

        $open = $db->table('loan_installments')
                   ->where('loan_id', $loanId)
                   ->whereNotIn('payment_status', self::CLOSED)
                   ->countAllResults();

        if ($open === 0) {
            $db->table('loans')
               ->where('loan_id', $loanId)
               ->update(['loan_status' => 'Paid', 'updated_at' => $now]);
        }
    }

    /**
     * Loans whose open months owe less than their unpaid regular amounts: the
     * carry-over bug (fixed 27 Oct 2026) dropped earlier missed months when
     * several were missed in a row.
     *
     * @return array<int, float> loan_id => amount lost
     */
    public static function lostCarryOver(): array
    {
        $rows = db_connect()->query("SELECT l.loan_id,
                ROUND(SUM(li.amount_due) - SUM(li.amount_paid), 2) AS should_owe,
                ROUND(SUM(CASE WHEN li.payment_status NOT IN ('Paid', 'Partial')
                               THEN GREATEST(li.amount_due + li.carried_over - li.amount_paid, 0) ELSE 0 END), 2) AS owed
            FROM loans l JOIN loan_installments li ON li.loan_id = l.loan_id
            WHERE l.loan_status IN ('Active', 'Defaulted')
            GROUP BY l.loan_id")->getResultArray();

        $lost = [];
        foreach ($rows as $r) {
            $gap = round((float) $r['should_owe'] - (float) $r['owed'], 2);
            if ($gap > 0.009) {
                $lost[(int) $r['loan_id']] = $gap;
            }
        }

        return $lost;
    }

    /**
     * Put the amounts lost by the carry-over bug back on each loan's first
     * open month. Returns the loans repaired (loan_id => amount restored).
     *
     * @return array<int, float>
     */
    public static function repairLostCarryOver(): array
    {
        $db       = db_connect();
        $repaired = [];
        foreach (self::lostCarryOver() as $loanId => $gap) {
            $open = $db->table('loan_installments')->where('loan_id', $loanId)
                ->whereNotIn('payment_status', self::CLOSED)->orderBy('installment_number')->limit(1)->get()->getRowArray();
            if (! $open) {
                continue;
            }
            $carried = round((float) $open['carried_over'] + $gap, 2);
            $db->table('loan_installments')->where('installment_id', $open['installment_id'])->update([
                'carried_over'      => $carried,
                'remaining_balance' => round((float) $open['amount_due'] + $carried - (float) $open['amount_paid'], 2),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
            $repaired[$loanId] = $gap;
        }

        return $repaired;
    }

    // -------------------------------------------------------------------------
    // Loan limit
    // -------------------------------------------------------------------------

    /**
     * Compute the maximum loan amount a member's cooperative account may apply for.
     * Maximum = 2 × the contributions the account still holds (approved
     * contributions less approved withdrawals and loan offsets), so money
     * already taken out cannot back a loan.
     *
     * @param  int   $accountId
     * @return float
     */
    public function checkMaxLoanLimit(int $accountId): float
    {
        return round(ContributionBalance::forAccount($accountId)['balance'] * 2, 2);
    }
}
