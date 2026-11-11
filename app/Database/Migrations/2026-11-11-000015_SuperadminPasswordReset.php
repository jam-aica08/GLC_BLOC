<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * Superadmin-controlled member password reset:
 *  - users.must_change_password: the member must choose a new password at the next sign-in;
 *  - blockchain_ledger.event_type / event_actor_id / event_subject_id, and a
 *    nullable financial_record_id, so a PASSWORD_RESET is hash-linked in the
 *    same SHA-256 chain as the financial records.
 */
class SuperadminPasswordReset extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        foreach (['event_subject_id', 'event_actor_id', 'event_type'] as $column) {
            if ($this->db->fieldExists($column, 'blockchain_ledger')) {
                $this->forge->dropColumn('blockchain_ledger', $column);
            }
        }
        if ($this->db->fieldExists('must_change_password', 'users')) {
            $this->forge->dropColumn('users', 'must_change_password');
        }
    }
}
