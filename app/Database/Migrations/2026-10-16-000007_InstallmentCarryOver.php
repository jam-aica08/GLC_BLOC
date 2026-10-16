<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * Month-by-month carry-over: an underpaid installment is closed as 'Partial'
 * and its unpaid part is added to the next installment's carried_over.
 * amount_due keeps the regular monthly amount. Safe to re-run.
 */
class InstallmentCarryOver extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('carried_over', 'loan_installments')) {
            $this->forge->dropColumn('loan_installments', 'carried_over');
        }
    }
}
