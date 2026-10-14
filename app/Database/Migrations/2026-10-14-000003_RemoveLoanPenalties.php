<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The cooperative does not charge late-payment penalties.
 */
class RemoveLoanPenalties extends Migration
{
    public function up(): void
    {
        $this->forge->dropColumn('loan_installments', ['penalty_amount', 'penalty_paid', 'penalty_status']);
        $this->forge->dropColumn('loan_payments', 'penalty_amount');
        $this->forge->dropColumn('loan_payment_installments', 'penalty_amount');
    }

    public function down(): void
    {
        $this->forge->addColumn('loan_installments', [
            'penalty_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00, 'after' => 'remaining_balance'],
            'penalty_paid'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00, 'after' => 'penalty_amount'],
            'penalty_status' => ['type' => 'ENUM', 'constraint' => ['None', 'Due', 'Paid'], 'default' => 'None', 'after' => 'payment_status'],
        ]);
        $this->forge->addColumn('loan_payments', [
            'penalty_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00, 'after' => 'interest_amount'],
        ]);
        $this->forge->addColumn('loan_payment_installments', [
            'penalty_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00, 'after' => 'amount_applied'],
        ]);
    }
}
