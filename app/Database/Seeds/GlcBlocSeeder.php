<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class GlcBlocSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedAdminUser();
        $this->seedSampleData();
    }

    private function seedRoles(): void
    {
        $data = [
            ['role_id' => 1, 'role_name' => 'Admin'],
            ['role_id' => 2, 'role_name' => 'Member'],
        ];

        // Use insertBatch for efficiency; ignore if already seeded
        foreach ($data as $role) {
            $existing = $this->db->table('roles')->where('role_id', $role['role_id'])->get()->getRow();
            if ($existing === null) {
                $this->db->table('roles')->insert($role);
            }
        }
    }

    private function seedAdminUser(): void
    {
        $existing = $this->db->table('users')->where('username', 'admin')->get()->getRow();
        if ($existing !== null) {
            return;
        }

        $this->db->table('users')->insert([
            'role_id'        => 1,
            'username'       => 'admin',
            'email'          => 'admin@glcbloc.com',
            'password_hash'  => password_hash('Admin@123', PASSWORD_BCRYPT),
            'account_status' => 'Active',
        ]);

        // The original administrator is the super admin (the only one who can read the audit logs).
        if ($this->db->fieldExists('is_super_admin', 'users')) {
            $this->db->table('users')->where('username', 'admin')->update(['is_super_admin' => 1]);
        }
    }

    private function seedSampleData(): void
    {
        // ── Sample member user ───────────────────────────────────────────────
        $existingUser = $this->db->table('users')->where('username', 'jdoe')->get()->getRow();
        if ($existingUser !== null) {
            return; // Sample data already seeded
        }

        $this->db->table('users')->insert([
            'role_id'        => 2,
            'username'       => 'jdoe',
            'email'          => 'jdoe@example.com',
            'password_hash'  => password_hash('Member@123', PASSWORD_BCRYPT),
            'account_status' => 'Active',
        ]);

        $memberUserId = $this->db->insertID();

        // ── Sample member ────────────────────────────────────────────────────
        $this->db->table('members')->insert([
            'user_id'          => $memberUserId,
            'member_number'    => 'MEM-001',
            'first_name'       => 'John',
            'last_name'        => 'Doe',
            'membership_date'  => '2026-01-01',
            'membership_status'=> 'Active',
        ]);

        $memberId = $this->db->insertID();

        // ── Sample cooperative account ────────────────────────────────────────
        $this->db->table('cooperative_accounts')->insert([
            'member_id'           => $memberId,
            'account_number'      => 'ACC-2026-001',
            'account_type'        => 'Pioneer',
            'account_start_date'  => '2026-01-01',
            'monthly_contribution'=> 300.00,
            'account_status'      => 'Active',
        ]);

        $accountId = $this->db->insertID();

        // ── Sample financial record ───────────────────────────────────────────
        $adminUser = $this->db->table('users')->where('username', 'admin')->get()->getRow();
        $adminId   = $adminUser ? $adminUser->user_id : null;

        $this->db->table('financial_records')->insert([
            'account_id'      => $accountId,
            'record_type'     => 'Contribution',
            'transaction_date'=> '2026-01-05',
            'amount'          => 300.00,
            'approval_status' => 'Approved',
            'processed_by'    => $adminId,
            'first_approved_by'  => $adminId,
            'first_approval_date'=> '2026-01-06 09:00:00',
            'second_approved_by' => $adminId,
            'second_approval_date'=> '2026-01-06 10:00:00',
            'reference_id'    => null,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $financialRecordId = $this->db->insertID();

        // ── Sample contribution ──────────────────────────────────────────────
        $this->db->table('contributions')->insert([
            'account_id'         => $accountId,
            'contribution_period'=> '2026-01',
            'amount'             => 300.00,
            'payment_date'       => '2026-01-05',
            'payment_status'     => 'Approved',
            'financial_record_id'=> $financialRecordId,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        // ── Sample blockchain ledger entry (genesis, same format as the app) ──
        $record = $this->db->table('financial_records')->where('financial_record_id', $financialRecordId)->get()->getRowArray();
        (new \App\Services\LedgerService())->createEntry((int) $financialRecordId, $record);

        // ── Sample analytics snapshot ─────────────────────────────────────────
        $this->db->table('analytics_snapshots')->insert([
            'snapshot_date' => '2026-01-31',
            'period_type'   => 'Monthly',
            'totals'        => json_encode([
                'total_contributions' => 300.00,
                'total_loans'         => 0.00,
                'total_dividends'     => 0.00,
                'active_members'      => 1,
                'active_accounts'     => 1,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
