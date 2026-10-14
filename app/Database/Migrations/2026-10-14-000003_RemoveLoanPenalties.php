<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The cooperative does not charge late-payment penalties.
 * Safe to re-run: only drops columns that still exist.
 */
class RemoveLoanPenalties extends Migration
{
    private const COLUMNS = [
        'loan_installments'         => ['penalty_amount', 'penalty_paid', 'penalty_status'],
        'loan_payments'             => ['penalty_amount'],
        'loan_payment_installments' => ['penalty_amount'],
    ];

    public function up(): void
    {
        $this->db->resetDataCache();

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->db->fieldExists($column, $table)) {
                    $this->forge->dropColumn($table, $column);
                }
            }
        }
    }

    public function down(): void
    {
        $add = [
            'loan_installments' => [
                'penalty_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
                'penalty_paid'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
                'penalty_status' => ['type' => 'ENUM', 'constraint' => ['None', 'Due', 'Paid'], 'default' => 'None'],
            ],
            'loan_payments'             => ['penalty_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00]],
            'loan_payment_installments' => ['penalty_amount' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00]],
        ];
        foreach ($add as $table => $fields) {
            foreach ($fields as $name => $def) {
                if (! $this->db->fieldExists($name, $table)) {
                    $this->forge->addColumn($table, [$name => $def]);
                }
            }
        }
    }
}
