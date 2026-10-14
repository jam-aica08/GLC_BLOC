<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Re-checks the schema changes from 000003/000004 against the actual tables.
 * A hand-run SQL script could record those migrations as applied even when
 * some of its statements failed; this repairs that. Safe to re-run.
 */
class EnsureSchema extends Migration
{
    public function up(): void
    {
        $penaltyColumns = [
            'loan_installments'         => ['penalty_amount', 'penalty_paid', 'penalty_status'],
            'loan_payments'             => ['penalty_amount'],
            'loan_payment_installments' => ['penalty_amount'],
        ];
        foreach ($penaltyColumns as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->db->fieldExists($column, $table)) {
                    $this->forge->dropColumn($table, $column);
                }
            }
        }

        $this->db->query("ALTER TABLE cooperative_accounts MODIFY account_status ENUM('Active','Inactive','Closed','Pending') NOT NULL DEFAULT 'Active'");
        $this->db->query("UPDATE cooperative_accounts SET monthly_contribution = 300.00");

        if (! $this->db->fieldExists('payment_method', 'loan_payments')) {
            $this->forge->addColumn('loan_payments', [
                'payment_method' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'amount_paid'],
            ]);
        }
    }

    public function down(): void
    {
        // Repair only; nothing to undo.
    }
}
