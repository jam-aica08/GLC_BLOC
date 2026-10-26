<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * Data retention policy (users.last_activity_at, the members' review, legal
 * hold and anonymization columns) and deactivating one account at a time
 * (cooperative_accounts.deactivated_*, account_requests.request_type).
 */
class DataRetentionAndAccountDeactivation extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        $drop = [
            'users'                => ['last_activity_at'],
            'members'              => ['retention_flagged_at', 'retention_reviewed_at', 'legal_hold', 'legal_hold_reason', 'login_data_removed_at', 'anonymized_at'],
            'cooperative_accounts' => ['deactivated_at', 'deactivated_by', 'deactivation_reason'],
            'account_requests'     => ['request_type', 'reason'],
        ];
        foreach ($drop as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->db->fieldExists($column, $table)) {
                    $this->forge->dropColumn($table, $column);
                }
            }
        }
    }
}
