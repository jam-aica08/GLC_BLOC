<?php

namespace App\Libraries;

/**
 * Lists and applies database migrations that have not run yet, so an admin
 * can bring a hosted database up to date from the Settings page.
 */
class DatabaseUpdates
{
    /**
     * @return array{pending: list<string>, problems: list<string>, error: ?string}
     */
    public static function status(): array
    {
        $status = ['pending' => [], 'problems' => [], 'error' => null];

        try {
            $runner  = service('migrations');
            $applied = [];
            foreach ($runner->getHistory() as $row) {
                $applied[$row->version . '|' . $row->class] = true;
            }
            foreach ($runner->findMigrations() as $migration) {
                if (! isset($applied[$migration->version . '|' . $migration->class])) {
                    $status['pending'][] = $migration->name;
                }
            }

            // Check the tables themselves, not just the migration records.
            $db = db_connect();
            $db->resetDataCache();
            if (! $db->fieldExists('payment_method', 'loan_payments')) {
                $status['problems'][] = 'loan_payments.payment_method is missing';
            }
            if ($db->fieldExists('penalty_amount', 'loan_installments')) {
                $status['problems'][] = 'old penalty columns are still present';
            }
        } catch (\Throwable $e) {
            log_message('error', 'DatabaseUpdates::status failed: ' . $e->getMessage());
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * Bring the tables in line with the current code: no penalty columns,
     * 'Pending' account status, PHP 300 monthly contribution, payment_method.
     * Safe to run any number of times.
     */
    public static function repairSchema($db, $forge): void
    {
        $db->resetDataCache();

        $penaltyColumns = [
            'loan_installments'         => ['penalty_amount', 'penalty_paid', 'penalty_status'],
            'loan_payments'             => ['penalty_amount'],
            'loan_payment_installments' => ['penalty_amount'],
        ];
        foreach ($penaltyColumns as $table => $columns) {
            foreach ($columns as $column) {
                if ($db->fieldExists($column, $table)) {
                    $forge->dropColumn($table, $column);
                }
            }
        }

        $db->query("ALTER TABLE cooperative_accounts MODIFY account_status ENUM('Active','Inactive','Closed','Pending') NOT NULL DEFAULT 'Active'");
        $db->query("UPDATE cooperative_accounts SET monthly_contribution = 300.00");

        if (! $db->fieldExists('payment_method', 'loan_payments')) {
            $forge->addColumn('loan_payments', [
                'payment_method' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'amount_paid'],
            ]);
        }

        $db->resetDataCache();
    }

    public static function needsUpdate(): bool
    {
        $s = self::status();
        return $s['pending'] !== [] || $s['problems'] !== [] || $s['error'] !== null;
    }

    /**
     * Apply every pending migration. Returns null on success or an error message.
     */
    public static function apply(): ?string
    {
        try {
            service('migrations')->latest();
            // Also repair directly, in case migration files are missing from the deployment.
            self::repairSchema(db_connect(), \Config\Database::forge());
        } catch (\Throwable $e) {
            log_message('error', 'DatabaseUpdates::apply failed: ' . $e->getMessage());
            return $e->getMessage();
        }

        $after = self::status();
        if ($after['problems'] !== []) {
            return 'Updates ran, but: ' . implode('; ', $after['problems']);
        }

        return null;
    }
}
