<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateGlcBlocSchema extends Migration
{
    public function up(): void
    {
        // ── roles ────────────────────────────────────────────────────────────
        $this->forge->addField([
            'role_id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'role_name' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
        ]);
        $this->forge->addKey('role_id', true);
        $this->forge->addUniqueKey('role_name');
        $this->forge->createTable('roles', true);

        // ── users ────────────────────────────────────────────────────────────
        $this->forge->addField([
            'user_id'       => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'role_id'       => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'username'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'account_status'=> ['type' => 'ENUM', 'constraint' => ['Active', 'Inactive', 'Suspended'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('user_id', true);
        $this->forge->addUniqueKey('username');
        $this->forge->addUniqueKey('email');
        $this->forge->addKey('role_id');
        $this->forge->createTable('users', true);

        $this->db->query('ALTER TABLE users ADD CONSTRAINT fk_users_role
            FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        // ── members ──────────────────────────────────────────────────────────
        $this->forge->addField([
            'member_id'        => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'member_number'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false],
            'first_name'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'last_name'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'membership_date'  => ['type' => 'DATE', 'null' => false],
            'membership_status'=> ['type' => 'ENUM', 'constraint' => ['Active', 'Inactive', 'Suspended'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('member_id', true);
        $this->forge->addUniqueKey('user_id');
        $this->forge->addUniqueKey('member_number');
        $this->forge->createTable('members', true);

        $this->db->query('ALTER TABLE members ADD CONSTRAINT fk_members_user
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE');

        // ── cooperative_accounts ─────────────────────────────────────────────
        $this->forge->addField([
            'account_id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'member_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'account_number'      => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => false],
            'account_type'        => ['type' => 'ENUM', 'constraint' => ['Pioneer', 'Basic'], 'null' => false],
            'account_start_date'  => ['type' => 'DATE', 'null' => false],
            'monthly_contribution'=> ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 300.00],
            'account_status'      => ['type' => 'ENUM', 'constraint' => ['Active', 'Inactive', 'Closed'], 'default' => 'Active'],
        ]);
        $this->forge->addKey('account_id', true);
        $this->forge->addUniqueKey('account_number');
        $this->forge->addKey('member_id');
        $this->forge->createTable('cooperative_accounts', true);

        $this->db->query('ALTER TABLE cooperative_accounts ADD CONSTRAINT fk_coop_accounts_member
            FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        // ── financial_records ────────────────────────────────────────────────
        $this->forge->addField([
            'financial_record_id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'record_type'           => ['type' => 'ENUM', 'constraint' => ['Contribution', 'Loan Disbursement', 'Loan Payment', 'Dividend'], 'null' => false],
            'transaction_date'      => ['type' => 'DATE', 'null' => false],
            'amount'                => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'approval_status'       => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Rejected'], 'default' => 'Pending'],
            'processed_by'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'first_approved_by'     => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'first_approval_date'   => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'second_approved_by'    => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'second_approval_date'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'reference_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('financial_record_id', true);
        $this->forge->addKey('account_id');
        $this->forge->addKey('approval_status');
        $this->forge->addKey('record_type');
        $this->forge->createTable('financial_records', true);

        // Fix 4.8: RESTRICT prevents cascade deletion of financial history
        $this->db->query('ALTER TABLE financial_records ADD CONSTRAINT fk_financial_records_account
            FOREIGN KEY (account_id) REFERENCES cooperative_accounts(account_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE financial_records ADD CONSTRAINT fk_financial_records_processed_by
            FOREIGN KEY (processed_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE financial_records ADD CONSTRAINT fk_financial_records_first_approved_by
            FOREIGN KEY (first_approved_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE financial_records ADD CONSTRAINT fk_financial_records_second_approved_by
            FOREIGN KEY (second_approved_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── contributions ────────────────────────────────────────────────────
        $this->forge->addField([
            'contribution_id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'contribution_period'=> ['type' => 'VARCHAR', 'constraint' => 7, 'null' => false, 'comment' => 'YYYY-MM'],
            'amount'            => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 300.00],
            'payment_date'      => ['type' => 'DATE', 'null' => true, 'default' => null],
            'payment_status'    => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Rejected'], 'default' => 'Pending'],
            'financial_record_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('contribution_id', true);
        $this->forge->addKey('account_id');
        $this->forge->addKey('payment_status');
        $this->forge->createTable('contributions', true);

        $this->db->query('ALTER TABLE contributions ADD CONSTRAINT fk_contributions_account
            FOREIGN KEY (account_id) REFERENCES cooperative_accounts(account_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE contributions ADD CONSTRAINT fk_contributions_financial_record
            FOREIGN KEY (financial_record_id) REFERENCES financial_records(financial_record_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── loans ────────────────────────────────────────────────────────────
        $this->forge->addField([
            'loan_id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'total_contributions'=> ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'maximum_loan_limit'=> ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'loan_amount'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'interest_rate'     => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => false],
            'interest_amount'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'loan_term_months'  => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'application_date'  => ['type' => 'DATE', 'null' => false],
            'approval_date'     => ['type' => 'DATE', 'null' => true, 'default' => null],
            'release_date'      => ['type' => 'DATE', 'null' => true, 'default' => null],
            'loan_status'       => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Active', 'Paid', 'Rejected', 'Defaulted'], 'default' => 'Pending'],
            'approved_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('loan_id', true);
        $this->forge->addKey('account_id');
        $this->forge->addKey('loan_status');
        $this->forge->createTable('loans', true);

        $this->db->query('ALTER TABLE loans ADD CONSTRAINT fk_loans_account
            FOREIGN KEY (account_id) REFERENCES cooperative_accounts(account_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE loans ADD CONSTRAINT fk_loans_approved_by
            FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── loan_installments ────────────────────────────────────────────────
        $this->forge->addField([
            'installment_id'    => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'loan_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'installment_number'=> ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'due_date'          => ['type' => 'DATE', 'null' => false],
            'principal_amount'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'interest_amount'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'amount_due'        => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'amount_paid'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'remaining_balance' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'penalty_amount'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'penalty_paid'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'payment_date'      => ['type' => 'DATE', 'null' => true, 'default' => null],
            'payment_status'    => ['type' => 'ENUM', 'constraint' => ['Upcoming', 'Due', 'Late', 'Paid'], 'default' => 'Upcoming'],
            'penalty_status'    => ['type' => 'ENUM', 'constraint' => ['None', 'Due', 'Paid'], 'default' => 'None'],
            'financial_record_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('installment_id', true);
        $this->forge->addKey('loan_id');
        $this->forge->addKey('payment_status');
        $this->forge->addKey('due_date');
        $this->forge->createTable('loan_installments', true);

        $this->db->query('ALTER TABLE loan_installments ADD CONSTRAINT fk_loan_installments_loan
            FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE loan_installments ADD CONSTRAINT fk_loan_installments_financial_record
            FOREIGN KEY (financial_record_id) REFERENCES financial_records(financial_record_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── loan_payments ────────────────────────────────────────────────────
        $this->forge->addField([
            'payment_id'        => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'loan_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'payment_date'      => ['type' => 'DATE', 'null' => false],
            'amount_paid'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'principal_amount'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'interest_amount'   => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'penalty_amount'    => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'payment_status'    => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Rejected'], 'default' => 'Pending'],
            'financial_record_id'=> ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('payment_id', true);
        $this->forge->addKey('loan_id');
        $this->forge->addKey('payment_status');
        $this->forge->createTable('loan_payments', true);

        $this->db->query('ALTER TABLE loan_payments ADD CONSTRAINT fk_loan_payments_loan
            FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE loan_payments ADD CONSTRAINT fk_loan_payments_financial_record
            FOREIGN KEY (financial_record_id) REFERENCES financial_records(financial_record_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── loan_payment_installments ────────────────────────────────────────
        $this->forge->addField([
            'payment_installment_id' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'payment_id'             => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'installment_id'         => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'amount_applied'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'penalty_amount'         => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
        ]);
        $this->forge->addKey('payment_installment_id', true);
        $this->forge->addKey('payment_id');
        $this->forge->addKey('installment_id');
        $this->forge->createTable('loan_payment_installments', true);

        $this->db->query('ALTER TABLE loan_payment_installments ADD CONSTRAINT fk_lpi_payment
            FOREIGN KEY (payment_id) REFERENCES loan_payments(payment_id) ON DELETE CASCADE ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE loan_payment_installments ADD CONSTRAINT fk_lpi_installment
            FOREIGN KEY (installment_id) REFERENCES loan_installments(installment_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        // ── dividends ────────────────────────────────────────────────────────
        $this->forge->addField([
            'dividend_id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'account_id'            => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'distribution_year'     => ['type' => 'YEAR', 'null' => false],
            'profit_pool'           => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'eligible_account_count'=> ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'dividend_per_account'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0.00],
            'dividend_amount'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'null' => false],
            'distribution_date'     => ['type' => 'DATE', 'null' => true, 'default' => null],
            'dividend_status'       => ['type' => 'ENUM', 'constraint' => ['Pending', 'Approved', 'Distributed'], 'default' => 'Pending'],
            'financial_record_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'            => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('dividend_id', true);
        $this->forge->addKey('account_id');
        $this->forge->addKey('dividend_status');
        $this->forge->addKey('distribution_year');
        $this->forge->createTable('dividends', true);

        $this->db->query('ALTER TABLE dividends ADD CONSTRAINT fk_dividends_account
            FOREIGN KEY (account_id) REFERENCES cooperative_accounts(account_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        $this->db->query('ALTER TABLE dividends ADD CONSTRAINT fk_dividends_financial_record
            FOREIGN KEY (financial_record_id) REFERENCES financial_records(financial_record_id) ON DELETE SET NULL ON UPDATE CASCADE');

        // ── blockchain_ledger ────────────────────────────────────────────────
        // Fix 4.5: verification_status enum must be 'Valid','Tampered'
        $this->forge->addField([
            'ledger_id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'financial_record_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'transaction_hash'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'previous_hash'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'ledger_sequence'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'recorded_at'         => ['type' => 'DATETIME', 'null' => false],
            'verification_status' => ['type' => 'ENUM', 'constraint' => ['Valid', 'Tampered'], 'default' => 'Valid'],
        ]);
        $this->forge->addKey('ledger_id', true);
        $this->forge->addUniqueKey('ledger_sequence');
        $this->forge->addUniqueKey('transaction_hash');
        $this->forge->addKey('financial_record_id');
        $this->forge->createTable('blockchain_ledger', true);

        $this->db->query('ALTER TABLE blockchain_ledger ADD CONSTRAINT fk_blockchain_financial_record
            FOREIGN KEY (financial_record_id) REFERENCES financial_records(financial_record_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        // ── analytics_snapshots ──────────────────────────────────────────────
        $this->forge->addField([
            'snapshot_id'   => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'snapshot_date' => ['type' => 'DATE', 'null' => false],
            'period_type'   => ['type' => 'ENUM', 'constraint' => ['Monthly', 'Quarterly', 'Annual'], 'null' => false],
            'totals'        => ['type' => 'JSON', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('snapshot_id', true);
        $this->forge->addKey('snapshot_date');
        $this->forge->addKey('period_type');
        $this->forge->createTable('analytics_snapshots', true);
    }

    public function down(): void
    {
        // Drop in reverse dependency order
        $this->forge->dropTable('analytics_snapshots', true);
        $this->forge->dropTable('blockchain_ledger', true);
        $this->forge->dropTable('dividends', true);
        $this->forge->dropTable('loan_payment_installments', true);
        $this->forge->dropTable('loan_payments', true);
        $this->forge->dropTable('loan_installments', true);
        $this->forge->dropTable('loans', true);
        $this->forge->dropTable('contributions', true);
        $this->forge->dropTable('financial_records', true);
        $this->forge->dropTable('cooperative_accounts', true);
        $this->forge->dropTable('members', true);
        $this->forge->dropTable('users', true);
        $this->forge->dropTable('roles', true);
    }
}
