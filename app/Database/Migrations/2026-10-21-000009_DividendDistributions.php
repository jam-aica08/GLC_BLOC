<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * Dividend distribution batches: dividend_distributions (one live batch per
 * year, two-admin approval), dividends.distribution_id and a 'Rejected'
 * dividend status. Safe to re-run.
 */
class DividendDistributions extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('distribution_id', 'dividends')) {
            $this->forge->dropColumn('dividends', 'distribution_id');
        }
        $this->forge->dropTable('dividend_distributions', true);
    }
}
