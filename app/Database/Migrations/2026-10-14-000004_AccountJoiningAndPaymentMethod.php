<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * - New accounts start as 'Pending' until contributions from January of their
 *   start year are complete.
 * - Every account owes a flat PHP 300 per month.
 * - Loan payments record how the member paid.
 *
 * Safe to re-run on a database that was partly upgraded by hand.
 */
class AccountJoiningAndPaymentMethod extends Migration
{
    public function up(): void
    {
        // Appending an ENUM value keeps existing rows valid (and is supported by TiDB).
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
        if ($this->db->fieldExists('payment_method', 'loan_payments')) {
            $this->forge->dropColumn('loan_payments', 'payment_method');
        }
        $this->db->query("UPDATE cooperative_accounts SET account_status = 'Inactive' WHERE account_status = 'Pending'");
        $this->db->query("ALTER TABLE cooperative_accounts MODIFY account_status ENUM('Active','Inactive','Closed') NOT NULL DEFAULT 'Active'");
    }
}
