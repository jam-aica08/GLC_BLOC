<?php

namespace App\Database\Migrations;

use App\Libraries\DatabaseUpdates;
use CodeIgniter\Database\Migration;

/**
 * Re-checks the schema changes from 000003/000004 against the actual tables.
 * A hand-run SQL script could record those migrations as applied even when
 * some of its statements failed; this repairs that. Safe to re-run.
 */
class EnsureSchema extends Migration
{
    public function up(): void
    {
        DatabaseUpdates::repairSchema($this->db, $this->forge);
    }

    public function down(): void
    {
        // Repair only; nothing to undo.
    }
}
