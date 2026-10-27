<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * Withdrawals of contributions (withdrawals table, 'Withdrawal' records),
 * loan offsets ('Loan Offset' records) and the administration share of each
 * dividend pool (dividend_distributions.admin_share*).
 */
class WithdrawalsOffsetsAdminShare extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        $this->forge->dropTable('withdrawals', true);   // its financial records stay
        foreach (['admin_share', 'admin_share_rate'] as $column) {
            if ($this->db->fieldExists($column, 'dividend_distributions')) {
                $this->forge->dropColumn('dividend_distributions', $column);
            }
        }
        // The 'Withdrawal' and 'Loan Offset' record types stay: removing them would
        // mean deleting ledgered financial records.
    }
}
