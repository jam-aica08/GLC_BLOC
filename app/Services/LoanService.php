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
    // Payment processing
    // -------------------------------------------------------------------------

    /**
     * Validate a payment and split it across the selected installments,
     * oldest first. Installment amounts are never changed: an underpaid
     * installment stays open, and its unpaid part must be paid before any
     * later installment (that is how the balance carries over).
     *
     * @param  int[] $installmentIds Installments the member ticked
     * @param  int[] $pendingIds     Installments already covered by a payment awaiting approval
     * @return array{valid: bool, error: ?string, allocation: array<int, array{installment_id: int, amount_applied: float, fully_paid: bool}>}
     */
    public function processPayment(int $loanId, float $amountPaid, array $installmentIds, array $pendingIds = []): array
    {
        $fail = fn(string $error) => ['valid' => false, 'error' => $error, 'allocation' => []];

        $amountPaid     = round($amountPaid, 2);
        $installmentIds = array_values(array_unique(array_map('intval', $installmentIds)));

        if ($amountPaid <= 0) {
            return $fail('Payment amount must be greater than zero.');
        }
        if (empty($installmentIds)) {
            return $fail('At least one installment must be selected.');
        }

        $rows = db_connect()->table('loan_installments')
                            ->where('loan_id', $loanId)
                            ->orderBy('installment_number', 'ASC')
                            ->get()->getResultArray();

        $selected = array_values(array_filter($rows, fn($r) => in_array((int) $r['installment_id'], $installmentIds, true)));
        if (count($selected) !== count($installmentIds)) {
            return $fail('One or more installments do not belong to this loan.');
        }

        $unpaid = fn(array $r) => round((float) $r['amount_due'] - (float) $r['amount_paid'], 2);

        foreach ($selected as $row) {
            if ($unpaid($row) <= 0) {
                return $fail("Installment #{$row['installment_number']} is already fully paid.");
            }
            if (in_array((int) $row['installment_id'], $pendingIds, true)) {
                return $fail("Installment #{$row['installment_number']} already has a payment waiting for approval.");
            }
        }

        // Older unpaid installments must be included so balances carry over in order.
        $latest = max(array_map(fn($r) => (int) $r['installment_number'], $selected));
        foreach ($rows as $row) {
            if ((int) $row['installment_number'] >= $latest) {
                break;
            }
            $id = (int) $row['installment_id'];
            if ($unpaid($row) > 0 && ! in_array($id, $installmentIds, true) && ! in_array($id, $pendingIds, true)) {
                return $fail("Please include installment #{$row['installment_number']} first; it still has an unpaid balance.");
            }
        }

        $totalUnpaid = round(array_sum(array_map($unpaid, $selected)), 2);
        if ($amountPaid > $totalUnpaid) {
            return $fail('The amount is more than the unpaid total of the selected installments (PHP ' . number_format($totalUnpaid, 2) . ').');
        }

        $remaining  = $amountPaid;
        $allocation = [];
        foreach ($selected as $row) {
            if ($remaining <= 0) {
                break;
            }
            $applied   = min($unpaid($row), $remaining);
            $remaining = round($remaining - $applied, 2);

            $allocation[] = [
                'installment_id' => (int) $row['installment_id'],
                'amount_applied' => $applied,
                'fully_paid'     => $applied >= $unpaid($row),
            ];
        }

        return ['valid' => true, 'error' => null, 'allocation' => $allocation];
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
