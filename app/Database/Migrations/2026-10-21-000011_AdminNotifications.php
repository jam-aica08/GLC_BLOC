<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * admin_notifications: one row per administrator, so each administrator
 * has their own unread count. Safe to re-run.
 */
class AdminNotifications extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        $this->forge->dropTable('admin_notifications', true);
    }
}
