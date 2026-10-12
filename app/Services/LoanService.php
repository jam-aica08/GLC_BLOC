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
     *    amount_due, remaining_balance]
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
        $remainingBalance = $loanAmount;
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
            $remainingBalance = round($remainingBalance - $principal, 2);

            $installments[] = [
                'installment_number' => $i,
                'due_date'           => $dueDate,
                'principal_amount'   => $principal,
                'interest_amount'    => $interest,
                'amount_due'         => $amountDue,
                'remaining_balance'  => max(0.00, $remainingBalance),
            ];
        }

        return $installments;
    }

    // -------------------------------------------------------------------------
    // Payment processing
    // -------------------------------------------------------------------------

    /**
     * Process a loan payment: validate, then allocate the paid amount across the
     * specified installments in order (oldest first).
     *
     * @param  int   $loanId         Loan being paid
     * @param  float $amountPaid     Total payment amount
     * @param  array $installmentIds Ordered list of installment_id values to cover
     * @return array {
     *   valid      : bool,
     *   error      : string|null,
     *   allocation : array  [{ installment_id, amount_applied, fully_paid }]
     * }
     */
    public function processPayment(int $loanId, float $amountPaid, array $installmentIds): array
    {
        // --- Validation ------------------------------------------------------
        if ($amountPaid <= 0) {
            return [
                'valid'      => false,
                'error'      => 'Payment amount must be greater than zero.',
                'allocation' => [],
            ];
        }

        if (empty($installmentIds)) {
            return [
                'valid'      => false,
                'error'      => 'At least one installment must be selected.',
                'allocation' => [],
            ];
        }

        $db           = db_connect();
        $placeholders = implode(',', array_fill(0, count($installmentIds), '?'));

        // Verify all installments belong to this loan
        $rows = $db->query(
            "SELECT installment_id, amount_due, amount_paid
               FROM loan_installments
              WHERE loan_id = ?
                AND installment_id IN ({$placeholders})
              ORDER BY installment_number ASC",
            array_merge([$loanId], $installmentIds)
        )->getResultArray();

        if (count($rows) !== count($installmentIds)) {
            return [
                'valid'      => false,
                'error'      => 'One or more installments do not belong to this loan.',
                'allocation' => [],
            ];
        }

        // --- Allocation (greedy, oldest-first) --------------------------------
        $remaining  = $amountPaid;
        $allocation = [];

        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $outstanding   = round((float) $row['amount_due'] - (float) $row['amount_paid'], 2);
            $applied       = min($outstanding, $remaining);
            $remaining     = round($remaining - $applied, 2);

            $allocation[] = [
                'installment_id' => (int) $row['installment_id'],
                'amount_applied' => $applied,
                'fully_paid'     => ($applied >= $outstanding),
            ];
        }

        return [
            'valid'      => true,
            'error'      => null,
            'allocation' => $allocation,
        ];
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
