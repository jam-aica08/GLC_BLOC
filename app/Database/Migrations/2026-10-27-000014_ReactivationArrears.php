<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * cooperative_accounts.reactivation_from: a reactivated account settles the
 * monthly contributions it missed from this month up to the current month
 * before it is Active again (Account Reactivation Policy).
 */
class ReactivationArrears extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        if ($this->db->fieldExists('reactivation_from', 'cooperative_accounts')) {
            $this->forge->dropColumn('cooperative_accounts', 'reactivation_from');
        }
    }
}
