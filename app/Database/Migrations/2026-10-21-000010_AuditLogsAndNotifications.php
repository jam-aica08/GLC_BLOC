<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * audit_logs (append-only, read by the super admin), member notifications and
 * users.is_super_admin (given to the original "admin" account). Safe to re-run.
 */
class AuditLogsAndNotifications extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        $this->forge->dropTable('notifications', true);
        $this->forge->dropTable('audit_logs', true);
        if ($this->db->fieldExists('is_super_admin', 'users')) {
            $this->forge->dropColumn('users', 'is_super_admin');
        }
    }
}
