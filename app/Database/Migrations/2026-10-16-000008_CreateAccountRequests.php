<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * account_requests: a member asks for a new account and an admin approves
 * (the account is then opened as Pending) or rejects it. Safe to re-run.
 */
class CreateAccountRequests extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        $this->forge->dropTable('account_requests', true);
    }
}
