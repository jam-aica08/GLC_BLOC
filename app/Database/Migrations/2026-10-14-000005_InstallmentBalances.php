<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Installments keep their original amount (principal + interest).
 * remaining_balance now means the unpaid part of each installment, and money
 * already received on a loan is re-applied oldest installment first, so an
 * underpaid installment stays open and its balance carries into the next.
 * Safe to re-run.
 */
class InstallmentBalances extends Migration
{
    public function up(): void
    {
        $now     = date('Y-m-d H:i:s');
        $loanIds = array_column($this->db->table('loan_installments')->select('loan_id')->distinct()->get()->getResultArray(), 'loan_id');

        foreach ($loanIds as $loanId) {
            $rows = $this->db->table('loan_installments')
                             ->where('loan_id', $loanId)
                             ->orderBy('installment_number', 'ASC')
                             ->get()->getResultArray();

            $pool = round(array_sum(array_column($rows, 'amount_paid')), 2);

            foreach ($rows as $row) {
                $due  = round((float) $row['principal_amount'] + (float) $row['interest_amount'], 2);
                $paid = min($due, $pool);
                $pool = round($pool - $paid, 2);

                $status = $row['payment_status'];
                if ($paid >= $due) {
                    $status = 'Paid';
                } elseif ($status === 'Paid') {
                    $status = 'Due';
                }

                $this->db->table('loan_installments')
                         ->where('installment_id', $row['installment_id'])
                         ->update([
                             'amount_due'        => $due,
                             'amount_paid'       => $paid,
                             'remaining_balance' => round($due - $paid, 2),
                             'payment_status'    => $status,
                             'updated_at'        => $now,
                         ]);
            }

            $open = $this->db->table('loan_installments')
                             ->where('loan_id', $loanId)
                             ->where('payment_status !=', 'Paid')
                             ->countAllResults();
            if ($open > 0) {
                $this->db->table('loans')->where('loan_id', $loanId)->where('loan_status', 'Paid')
                         ->update(['loan_status' => 'Active', 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        // Data normalisation only; nothing to undo.
    }
}
