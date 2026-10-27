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
            if (! $db->fieldExists('carried_over', 'loan_installments')) {
                $status['problems'][] = 'loan_installments.carried_over is missing';
            }
            if ($db->fieldExists('penalty_amount', 'loan_installments')) {
                $status['problems'][] = 'old penalty columns are still present';
            }
            if (! $db->tableExists('account_requests', false)) {
                $status['problems'][] = 'account_requests table is missing';
            }
            if (! $db->tableExists('dividend_distributions', false) || ! $db->fieldExists('distribution_id', 'dividends')) {
                $status['problems'][] = 'dividend distribution batches are missing';
            }
            if (! $db->tableExists('audit_logs', false) || ! $db->tableExists('notifications', false) || ! $db->fieldExists('is_super_admin', 'users')) {
                $status['problems'][] = 'audit logs, notifications or the super admin flag are missing';
            } elseif ($db->table('users')->where('is_super_admin', 1)->countAllResults() === 0) {
                $status['problems'][] = 'no super administrator is set';
            }
            if (! $db->tableExists('admin_notifications', false)) {
                $status['problems'][] = 'admin notifications table is missing';
            }
            if (! $db->fieldExists('last_activity_at', 'users') || ! $db->fieldExists('legal_hold', 'members')
                || ! $db->fieldExists('deactivated_at', 'cooperative_accounts')
                || ($db->tableExists('account_requests', false) && ! $db->fieldExists('request_type', 'account_requests'))) {
                $status['problems'][] = 'data retention and account deactivation columns are missing';
            }
            if (! $db->tableExists('withdrawals', false) || ! $db->fieldExists('admin_share', 'dividend_distributions')) {
                $status['problems'][] = 'withdrawals, loan offsets or the administration dividend share are missing';
            }
            if ($db->fieldExists('carried_over', 'loan_installments') && ($lost = \App\Services\LoanService::lostCarryOver())) {
                $status['problems'][] = count($lost) . ' loan(s) lost unpaid amounts from missed months (PHP '
                    . number_format(array_sum($lost), 2) . ' in all: loan ' . implode(', ', array_map(fn($id) => '#' . $id, array_keys($lost)))
                    . '); applying the updates puts them back';
            }
        } catch (\Throwable $e) {
            log_message('error', 'DatabaseUpdates::status failed: ' . $e->getMessage());
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    /**
     * Bring the tables in line with the current code: no penalty columns,
     * 'Pending' account status, PHP 300 monthly contribution, payment_method,
     * installment carry-over (carried_over column, 'Partial' status),
     * account_requests table, dividend_distributions batches (with
     * dividends.distribution_id and a 'Rejected' dividend status), audit_logs,
     * notifications, admin_notifications and users.is_super_admin. Safe to run any number of times.
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

        if (! $db->fieldExists('carried_over', 'loan_installments')) {
            $forge->addColumn('loan_installments', [
                'carried_over' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00, 'null' => false, 'after' => 'amount_due'],
            ]);
        }
        $db->query("ALTER TABLE loan_installments MODIFY payment_status ENUM('Upcoming','Due','Late','Paid','Partial') NOT NULL DEFAULT 'Upcoming'");

        // Members ask for a new account here; an admin approves or rejects it.
        if (! $db->tableExists('account_requests', false)) {
            $forge->addField([
                'request_id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'member_id'    => ['type' => 'INT', 'unsigned' => true],
                'account_type' => ['type' => 'ENUM', 'constraint' => ['Pioneer', 'Basic']],
                'status'       => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Rejected'], 'default' => 'Pending'],
                'account_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'reviewed_by'  => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'reviewed_at'  => ['type' => 'DATETIME', 'null' => true],
                'created_at'   => ['type' => 'DATETIME', 'null' => true],
                'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addKey('request_id', true);
            $forge->addKey(['member_id', 'status']);
            $forge->addForeignKey('member_id', 'members', 'member_id', 'CASCADE', 'CASCADE', 'fk_account_requests_member');
            $forge->createTable('account_requests', true);
        }

        // One row per year's dividend distribution. year_lock holds the year while
        // the batch is Pending or Approved and is cleared on rejection, so the
        // unique key allows only one live distribution per year.
        if (! $db->tableExists('dividend_distributions', false)) {
            $forge->addField([
                'distribution_id'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'distribution_year'    => ['type' => 'INT', 'unsigned' => true],
                'year_lock'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'profit_pool'          => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'basic_count'          => ['type' => 'INT', 'unsigned' => true],
                'pioneer_count'        => ['type' => 'INT', 'unsigned' => true],
                'total_weight'         => ['type' => 'DECIMAL', 'constraint' => '12,4'],
                'basic_share'          => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'pioneer_share'        => ['type' => 'DECIMAL', 'constraint' => '18,6'],
                'total_distributed'    => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'distribution_date'    => ['type' => 'DATE'],
                'status'               => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Rejected'], 'default' => 'Pending'],
                'created_by'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'first_approved_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'first_approval_date'  => ['type' => 'DATETIME', 'null' => true],
                'second_approved_by'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'second_approval_date' => ['type' => 'DATETIME', 'null' => true],
                'rejected_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'rejected_at'          => ['type' => 'DATETIME', 'null' => true],
                'created_at'           => ['type' => 'DATETIME', 'null' => true],
                'updated_at'           => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addKey('distribution_id', true);
            $forge->addUniqueKey('year_lock', 'uq_dividend_distributions_year');
            $forge->addKey('distribution_year');
            $forge->createTable('dividend_distributions', true);
        }
        $db->resetDataCache();
        if (! $db->fieldExists('distribution_id', 'dividends')) {
            $forge->addColumn('dividends', [
                'distribution_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'dividend_id'],
            ]);
            $db->query('ALTER TABLE dividends ADD INDEX idx_dividends_distribution (distribution_id)');
        }
        $db->query("ALTER TABLE dividends MODIFY dividend_status ENUM('Pending','Approved','Distributed','Rejected') NOT NULL DEFAULT 'Pending'");

        // The highest-level administrator: only they can read the audit logs.
        if (! $db->fieldExists('is_super_admin', 'users')) {
            $forge->addColumn('users', [
                'is_super_admin' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false, 'after' => 'account_status'],
            ]);
            $db->resetDataCache();
        }
        $hasSuper = $db->table('users')->where('is_super_admin', 1)->countAllResults() > 0;
        if (! $hasSuper) {
            $adminRole = $db->table('roles')->where('role_name', 'Admin')->get()->getRowArray();
            if ($adminRole) {
                $first = $db->table('users')->where('role_id', $adminRole['role_id'])->where('username', 'admin')->get()->getRowArray()
                      ?: $db->table('users')->where('role_id', $adminRole['role_id'])->orderBy('user_id', 'ASC')->limit(1)->get()->getRowArray();
                if ($first) {
                    $db->table('users')->where('user_id', $first['user_id'])->update(['is_super_admin' => 1]);
                }
            }
        }

        // Append-only record of who did what. Only the retention review deletes or rewrites rows here.
        if (! $db->tableExists('audit_logs', false)) {
            $forge->addField([
                'audit_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'user_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'username'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'role'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'action'      => ['type' => 'VARCHAR', 'constraint' => 100],
                'entity_type' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'entity_id'   => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'details'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'result'      => ['type' => 'ENUM', 'constraint' => ['Success', 'Failed'], 'default' => 'Success'],
                'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'created_at'  => ['type' => 'DATETIME'],
            ]);
            $forge->addKey('audit_id', true);
            $forge->addKey('created_at');
            $forge->addKey('user_id');
            $forge->createTable('audit_logs', true);
        }

        // Private updates for one member (one row per member, so read status is per member).
        if (! $db->tableExists('notifications', false)) {
            $forge->addField([
                'notification_id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'member_id'       => ['type' => 'INT', 'unsigned' => true],
                'type'            => ['type' => 'VARCHAR', 'constraint' => 30],
                'title'           => ['type' => 'VARCHAR', 'constraint' => 150],
                'message'         => ['type' => 'VARCHAR', 'constraint' => 500],
                'link'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'event_key'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'is_read'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'read_at'         => ['type' => 'DATETIME', 'null' => true],
                'created_at'      => ['type' => 'DATETIME'],
            ]);
            $forge->addKey('notification_id', true);
            $forge->addKey(['member_id', 'is_read']);
            $forge->addUniqueKey(['member_id', 'event_key'], 'uq_notifications_event');
            $forge->addForeignKey('member_id', 'members', 'member_id', 'CASCADE', 'CASCADE', 'fk_notifications_member');
            $forge->createTable('notifications', true);
        }

        // Private updates for one administrator (new requests to review, ledger problems).
        if (! $db->tableExists('admin_notifications', false)) {
            $forge->addField([
                'notification_id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'user_id'         => ['type' => 'INT', 'unsigned' => true],
                'type'            => ['type' => 'VARCHAR', 'constraint' => 30],
                'title'           => ['type' => 'VARCHAR', 'constraint' => 150],
                'message'         => ['type' => 'VARCHAR', 'constraint' => 500],
                'link'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'event_key'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'is_read'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'read_at'         => ['type' => 'DATETIME', 'null' => true],
                'created_at'      => ['type' => 'DATETIME'],
            ]);
            $forge->addKey('notification_id', true);
            $forge->addKey(['user_id', 'is_read']);
            $forge->addKey('event_key');
            $forge->addUniqueKey(['user_id', 'event_key'], 'uq_admin_notifications_event');
            $forge->addForeignKey('user_id', 'users', 'user_id', 'CASCADE', 'CASCADE', 'fk_admin_notifications_user');
            $forge->createTable('admin_notifications', true);
        }

        // Data retention (see Config\Retention): when each login was last used,
        // and where each member is in the inactivity review.
        $db->resetDataCache();
        if (! $db->fieldExists('last_activity_at', 'users')) {
            $forge->addColumn('users', [
                'last_activity_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
        }
        $memberColumns = [
            'retention_flagged_at'  => ['type' => 'DATETIME', 'null' => true],
            'retention_reviewed_at' => ['type' => 'DATETIME', 'null' => true],
            'legal_hold'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            'legal_hold_reason'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'login_data_removed_at' => ['type' => 'DATETIME', 'null' => true],
            'anonymized_at'         => ['type' => 'DATETIME', 'null' => true],
        ];
        foreach ($memberColumns as $name => $def) {
            if (! $db->fieldExists($name, 'members')) {
                $forge->addColumn('members', [$name => $def]);
            }
        }

        // One account of a member can be deactivated on its own (Inactive), keeping its records.
        $accountColumns = [
            'deactivated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deactivated_by'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'deactivation_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ];
        foreach ($accountColumns as $name => $def) {
            if (! $db->fieldExists($name, 'cooperative_accounts')) {
                $forge->addColumn('cooperative_accounts', [$name => $def]);
            }
        }

        // Members can also ask to deactivate or reactivate one of their accounts.
        if (! $db->fieldExists('request_type', 'account_requests')) {
            $forge->addColumn('account_requests', [
                'request_type' => ['type' => 'ENUM', 'constraint' => ['Open', 'Deactivate', 'Reactivate'], 'default' => 'Open', 'null' => false, 'after' => 'member_id'],
                'reason'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'account_type'],
            ]);
        }

        // Withdrawals of contributions and loan offsets are financial records too
        // (appending ENUM values keeps existing rows valid, and TiDB supports it).
        $db->query("ALTER TABLE financial_records MODIFY record_type ENUM('Contribution','Loan Disbursement','Loan Payment','Dividend','Withdrawal','Loan Offset') NOT NULL");

        // A member's request to withdraw contributions from one account; an admin approves it.
        if (! $db->tableExists('withdrawals', false)) {
            $forge->addField([
                'withdrawal_id'       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'account_id'          => ['type' => 'INT', 'unsigned' => true],
                'amount'              => ['type' => 'DECIMAL', 'constraint' => '15,2'],
                'method'              => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'reason'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'status'              => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Rejected'], 'default' => 'Pending'],
                'financial_record_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'requested_by'        => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'          => ['type' => 'DATETIME', 'null' => true],
                'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addKey('withdrawal_id', true);
            $forge->addKey(['account_id', 'status']);
            $forge->addKey('financial_record_id');
            $forge->addForeignKey('account_id', 'cooperative_accounts', 'account_id', 'CASCADE', 'RESTRICT', 'fk_withdrawals_account');
            $forge->createTable('withdrawals', true);
        }

        // The share of each dividend pool kept for administration (3% from Oct 2026).
        $db->resetDataCache();
        if (! $db->fieldExists('admin_share', 'dividend_distributions')) {
            $forge->addColumn('dividend_distributions', [
                'admin_share_rate' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0.00, 'null' => false, 'after' => 'profit_pool'],
                'admin_share'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00, 'null' => false, 'after' => 'admin_share_rate'],
            ]);
        }

        $db->resetDataCache();
    }

    /** Shown as a banner on the dashboard. Once the database is up to date, that is remembered for an hour. */
    public static function needsUpdate(): bool
    {
        return ! SchemaCache::passes('up_to_date', static function (): bool {
            $s = self::status();

            return $s['pending'] === [] && $s['problems'] === [] && $s['error'] === null;
        });
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

            // Restore loan amounts dropped by the carry-over bug (fixed 27 Oct 2026), and record it.
            if ($repaired = \App\Services\LoanService::repairLostCarryOver()) {
                AuditLog::record('Repaired loan balances', 'loan', null,
                    'Restored unpaid amounts lost by the carry-over bug: ' . implode(', ', array_map(
                        fn($id, $amount) => "loan #{$id} PHP " . number_format($amount, 2), array_keys($repaired), $repaired)) . '.');
            }
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
