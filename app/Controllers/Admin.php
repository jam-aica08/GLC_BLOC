<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\MemberModel;
use App\Models\CooperativeAccountModel;
use App\Models\ContributionModel;
use App\Models\FinancialRecordModel;
use App\Models\LoanModel;
use App\Models\LoanInstallmentModel;
use App\Services\LedgerService;
use App\Services\LoanService;
use App\Services\AnalyticsService;

/**
 * Admin controller.
 *
 * All state-changing actions use POST (Fix 4.3).
 * Role is verified server-side on every method (Fix 4.9).
 */
class Admin extends BaseController
{
    // -------------------------------------------------------------------------
    // Auth guard
    // -------------------------------------------------------------------------

    /**
     * Confirm the session contains a valid admin role.
     * Called at the top of every method.
     */
    private function requireAdmin(): void
    {
        $session = session();
        if (! $session->get('logged_in') || strtolower((string) $session->get('role')) !== 'admin') {
            $session->destroy();
            redirect()->to('/login')->send();
            exit;
        }
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboard(): string
    {
        $this->requireAdmin();

        $db = db_connect();

        $pendingContributions = $db->table('contributions')
                                   ->where('payment_status', 'Pending')
                                   ->countAllResults();

        $pendingLoans = $db->table('loans')
                           ->where('loan_status', 'Pending')
                           ->countAllResults();

        $pendingPayments = $db->table('loan_payments')
                              ->where('payment_status', 'Pending')
                              ->countAllResults();

        $totalMembers = $db->table('members')->countAllResults();

        $totalContributions = (float) ($db->table('financial_records')
            ->where('record_type', 'Contribution')
            ->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);

        $activeLoans = $db->table('loans')
            ->where('loan_status', 'Active')
            ->countAllResults();

        $pendingApprovals = $pendingContributions + $pendingLoans + $pendingPayments;

        $rawRecords = $db->table('financial_records fr')
                         ->select('fr.financial_record_id AS id, fr.record_type AS transaction_type, fr.amount, fr.created_at, fr.first_approved_by, fr.second_approved_by, ca.account_number, m.first_name, m.last_name')
                         ->join('cooperative_accounts ca', 'ca.account_id = fr.account_id', 'left')
                         ->join('members m', 'm.member_id = ca.member_id', 'left')
                         ->orderBy('fr.created_at', 'DESC')
                         ->limit(10)
                         ->get()
                         ->getResultArray();

        $recentFinancialRecords = array_map(function ($r) {
            $r['approval_count'] = (!empty($r['second_approved_by']) ? 2 : (!empty($r['first_approved_by']) ? 1 : 0));
            return $r;
        }, $rawRecords);

        $recentActivity = array_map(fn($r) => [
            'description' => ($r['transaction_type'] ?? 'Transaction') . ' — ₱' . number_format($r['amount'], 2),
            'created_at'  => $r['created_at'],
        ], array_slice($recentFinancialRecords, 0, 6));

        return view('admin/dashboard', [
            'totalMembers'           => $totalMembers,
            'totalContributions'     => $totalContributions,
            'activeLoans'            => $activeLoans,
            'pendingApprovals'       => $pendingApprovals,
            'pendingContributions'   => $pendingContributions,
            'pendingLoans'           => $pendingLoans,
            'pendingPayments'        => $pendingPayments,
            'recentFinancialRecords' => $recentFinancialRecords,
            'recentActivity'         => $recentActivity,
        ]);
    }

    // =========================================================================
    // Members
    // =========================================================================

    public function members(): string
    {
        $this->requireAdmin();

        $db = db_connect();
        $members = $db->table('members m')
                      ->select('m.member_id AS id, m.member_number, m.first_name, m.last_name, m.membership_date, m.membership_status AS status, u.username, u.email, u.account_status AS user_status, ca.account_number, ca.account_type, ca.account_status')
                      ->join('users u', 'u.user_id = m.user_id', 'left')
                      ->join('cooperative_accounts ca', 'ca.member_id = m.member_id', 'left')
                      ->orderBy('m.member_id', 'ASC')
                      ->get()
                      ->getResultArray();

        return view('admin/members', ['members' => $members]);
    }

    /**
     * POST: create user + member + cooperative_account atomically.
     */
    public function addMember()
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $db = db_connect();
        $db->transStart();

        try {
            // 1. Create user
            $userModel    = model(UserModel::class);
            $memberRoleId = $db->table('roles')
                               ->where('role_name', 'Member')
                               ->get()
                               ->getRowArray()['role_id'] ?? 2;

            $password     = $this->request->getPost('password');
            $userId       = $userModel->insert([
                'role_id'        => $memberRoleId,
                'username'       => $this->request->getPost('username'),
                'email'          => $this->request->getPost('email'),
                'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                'account_status' => 'Active',
            ]);

            if (! $userId) {
                throw new \RuntimeException(implode(' ', $userModel->errors()));
            }

            // 2. Create member
            $memberModel  = model(MemberModel::class);
            $memberNumber = $memberModel->getNextMemberNumber();
            $memberId     = $memberModel->insert([
                'user_id'           => $userId,
                'member_number'     => $memberNumber,
                'first_name'        => $this->request->getPost('first_name'),
                'last_name'         => $this->request->getPost('last_name'),
                'membership_date'   => date('Y-m-d'),
                'membership_status' => 'Active',
            ]);

            if (! $memberId) {
                throw new \RuntimeException(implode(' ', $memberModel->errors()));
            }

            // 3. Create cooperative_account
            $accountType   = $this->request->getPost('account_type') ?: 'Basic';
            $accountNumber = 'ACC-' . str_pad($memberId, 6, '0', STR_PAD_LEFT);

            $db->table('cooperative_accounts')->insert([
                'member_id'            => $memberId,
                'account_number'       => $accountNumber,
                'account_type'         => $accountType,
                'account_start_date'   => date('Y-m-d'),
                'monthly_contribution' => ($accountType === 'Pioneer') ? 500.00 : 300.00,
                'account_status'       => 'Active',
            ]);

            $db->transComplete();

            if (! $db->transStatus()) {
                throw new \RuntimeException('Transaction failed during member creation.');
            }

            session()->setFlashdata('success', "Member {$memberNumber} created successfully.");
        } catch (\Throwable $e) {
            $db->transRollback();
            session()->setFlashdata('error', 'Failed to add member: ' . $e->getMessage());
        }

        return redirect()->to('/admin/members');
    }

    /**
     * POST: set member status to Inactive.
     */
    public function deactivateMember(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $memberModel = model(MemberModel::class);
        $memberModel->update($id, ['membership_status' => 'Inactive']);

        session()->setFlashdata('success', 'Member deactivated.');
        return redirect()->to('/admin/members');
    }

    // =========================================================================
    // Contributions
    // =========================================================================

    public function contributions(): string
    {
        $this->requireAdmin();

        $filterStatus = $this->request->getGet('status') ?: '';
        $db = db_connect();

        $query = $db->table('contributions c')
                    ->select("c.contribution_id AS id, c.contribution_period, c.amount, c.payment_date, c.payment_status, c.created_at, ca.account_number, ca.account_type, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, fr.approval_status, fr.first_approved_by, fr.second_approved_by")
                    ->join('cooperative_accounts ca', 'ca.account_id = c.account_id', 'left')
                    ->join('members m', 'm.member_id = ca.member_id', 'left')
                    ->join('financial_records fr', 'fr.financial_record_id = c.financial_record_id', 'left')
                    ->orderBy('c.created_at', 'DESC');

        if ($filterStatus !== '') {
            $query->where('c.payment_status', $filterStatus);
        }

        $contributions = $query->get()->getResultArray();

        return view('admin/contributions', [
            'contributions' => $contributions,
            'filterStatus'  => $filterStatus,
        ]);
    }

    /**
     * POST: two-tier approval for a contribution (Fix 4.7).
     *
     * Tier 1: set first_approved_by
     * Tier 2: set second_approved_by (must be a DIFFERENT user — Fix 4.7),
     *         mark Approved, write ledger entry.
     */
    public function approveContribution(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/contributions');
        }

        $currentUserId = (int) session()->get('user_id');
        $db            = db_connect();
        $frModel       = model(FinancialRecordModel::class);

        $db->transStart();

        // Load the contribution
        $contribution = $db->table('contributions')
                           ->where('contribution_id', $id)
                           ->get()
                           ->getRowArray();

        if (! $contribution) {
            $db->transRollback();
            session()->setFlashdata('error', 'Contribution not found.');
            return redirect()->to('/admin/contributions');
        }

        $frId = (int) $contribution['financial_record_id'];
        $fr   = $frModel->find($frId);

        if (! $fr) {
            $db->transRollback();
            session()->setFlashdata('error', 'Financial record not found.');
            return redirect()->to('/admin/contributions');
        }

        if ($fr['approval_status'] !== 'Pending') {
            $db->transRollback();
            session()->setFlashdata('error', 'Contribution is no longer pending.');
            return redirect()->to('/admin/contributions');
        }

        $now = date('Y-m-d H:i:s');

        if (empty($fr['first_approved_by'])) {
            // --- Tier 1 ---------------------------------------------------------
            $affected = $db->table('financial_records')
                           ->where('financial_record_id', $frId)
                           ->where('first_approved_by IS NULL')          // concurrent guard
                           ->update([
                               'first_approved_by'   => $currentUserId,
                               'first_approval_date' => $now,
                               'updated_at'          => $now,
                           ]);

            if (! $db->affectedRows()) {
                $db->transRollback();
                session()->setFlashdata('error', 'Concurrent approval detected. Please reload.');
                return redirect()->to('/admin/contributions');
            }

            $db->transComplete();
            session()->setFlashdata('success', 'First approval recorded.');

        } elseif (empty($fr['second_approved_by'])) {
            // --- Tier 2 ---------------------------------------------------------
            // Fix 4.7: second approver must differ from first approver
            if ((int) $fr['first_approved_by'] === $currentUserId) {
                $db->transRollback();
                session()->setFlashdata('error', 'You cannot provide both approvals for the same contribution.');
                return redirect()->to('/admin/contributions');
            }

            // Atomic update with concurrent-approval guard
            $db->table('financial_records')
               ->where('financial_record_id', $frId)
               ->where('second_approved_by IS NULL')
               ->where('approval_status', 'Pending')
               ->update([
                   'second_approved_by'   => $currentUserId,
                   'second_approval_date' => $now,
                   'approval_status'      => 'Approved',
                   'updated_at'           => $now,
               ]);

            if (! $db->affectedRows()) {
                $db->transRollback();
                session()->setFlashdata('error', 'Concurrent approval detected. Please reload.');
                return redirect()->to('/admin/contributions');
            }

            // Update contribution status
            $db->table('contributions')
               ->where('contribution_id', $id)
               ->update(['payment_status' => 'Approved', 'updated_at' => $now]);

            $db->transComplete();

            if (! $db->transStatus()) {
                session()->setFlashdata('error', 'Database error during approval.');
                return redirect()->to('/admin/contributions');
            }

            // Write ledger entry (outside the DB transaction — ledger has its own integrity)
            $frFresh = $frModel->find($frId);
            try {
                $ledgerService = new LedgerService();
                $ledgerService->createEntry($frId, $frFresh);
            } catch (\Throwable $e) {
                log_message('error', 'LedgerService::createEntry failed for FR #' . $frId . ': ' . $e->getMessage());
            }

            session()->setFlashdata('success', 'Contribution fully approved and ledger entry created.');

        } else {
            $db->transRollback();
            session()->setFlashdata('error', 'Contribution has already been approved by two approvers.');
        }

        return redirect()->to('/admin/contributions');
    }

    /**
     * POST: reject a contribution.
     */
    public function rejectContribution(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/contributions');
        }

        $db    = db_connect();
        $frModel = model(FinancialRecordModel::class);
        $now   = date('Y-m-d H:i:s');

        $contribution = $db->table('contributions')
                           ->where('contribution_id', $id)
                           ->get()
                           ->getRowArray();

        if (! $contribution) {
            session()->setFlashdata('error', 'Contribution not found.');
            return redirect()->to('/admin/contributions');
        }

        $frId = (int) $contribution['financial_record_id'];

        $db->transStart();

        $frModel->update($frId, ['approval_status' => 'Rejected', 'updated_at' => $now]);
        $db->table('contributions')
           ->where('contribution_id', $id)
           ->update(['payment_status' => 'Rejected', 'updated_at' => $now]);

        $db->transComplete();

        session()->setFlashdata('success', 'Contribution rejected.');
        return redirect()->to('/admin/contributions');
    }

    // =========================================================================
    // Loans
    // =========================================================================

    public function loans(): string
    {
        $this->requireAdmin();

        $filterStatus = $this->request->getGet('status') ?: '';
        $db    = db_connect();

        $query = $db->table('loans l')
                    ->select("l.loan_id AS id, l.loan_amount, l.interest_rate, l.loan_term_months AS term_months, l.loan_status AS status, l.application_date AS applied_date, l.approval_date, l.release_date, l.account_id, ca.account_number, ca.account_type, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name")
                    ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                    ->join('members m', 'm.member_id = ca.member_id', 'left')
                    ->orderBy('l.application_date', 'DESC');

        if ($filterStatus !== '') {
            $query->where('l.loan_status', $filterStatus);
        }

        $loans = $query->get()->getResultArray();

        return view('admin/loans', [
            'loans'        => $loans,
            'filterStatus' => $filterStatus,
        ]);
    }

    /**
     * POST: approve a loan and generate installments via LoanService.
     */
    public function approveLoan(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/loans');
        }

        $currentUserId = (int) session()->get('user_id');
        $loanModel     = model(LoanModel::class);
        $loanService   = new LoanService();
        $db            = db_connect();
        $now           = date('Y-m-d H:i:s');
        $today         = date('Y-m-d');

        $loan = $loanModel->find($id);
        if (! $loan || $loan['loan_status'] !== 'Pending') {
            session()->setFlashdata('error', 'Loan not found or is not pending.');
            return redirect()->to('/admin/loans');
        }

        $releaseDate = $this->request->getPost('release_date') ?: $today;

        $db->transStart();

        // Update loan
        $loanModel->update($id, [
            'loan_status'   => 'Active',
            'approval_date' => $today,
            'release_date'  => $releaseDate,
            'approved_by'   => $currentUserId,
        ]);

        // Generate installments
        $installmentData = $loanService->calculateInstallments([
            'loan_amount'      => $loan['loan_amount'],
            'interest_rate'    => $loan['interest_rate'],
            'loan_term_months' => $loan['loan_term_months'],
            'release_date'     => $releaseDate,
        ]);

        $installmentModel = model(LoanInstallmentModel::class);
        foreach ($installmentData as $inst) {
            $installmentModel->insert(array_merge($inst, [
                'loan_id'        => $id,
                'amount_paid'    => 0.00,
                'penalty_amount' => 0.00,
                'penalty_paid'   => 0.00,
                'payment_status' => 'Upcoming',
                'penalty_status' => 'None',
            ]));
        }

        // Create financial record for loan disbursement
        $frModel = model(FinancialRecordModel::class);
        $frId    = $frModel->insert([
            'account_id'      => $loan['account_id'],
            'record_type'     => 'Loan Disbursement',
            'transaction_date'=> $releaseDate,
            'amount'          => $loan['loan_amount'],
            'approval_status' => 'Approved',
            'processed_by'    => $currentUserId,
            'first_approved_by'  => $currentUserId,
            'first_approval_date'=> $now,
            'second_approved_by' => $currentUserId,
            'second_approval_date'=> $now,
            'reference_id'    => $id,
        ]);

        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Failed to approve loan.');
            return redirect()->to('/admin/loans');
        }

        // Ledger entry
        if ($frId) {
            $frFresh = $frModel->find($frId);
            try {
                $ledgerService = new LedgerService();
                $ledgerService->createEntry((int) $frId, $frFresh);
            } catch (\Throwable $e) {
                log_message('error', 'LedgerService::createEntry failed for loan disbursement FR #' . $frId . ': ' . $e->getMessage());
            }
        }

        session()->setFlashdata('success', 'Loan approved and installments generated.');
        return redirect()->to('/admin/loans');
    }

    /**
     * POST: reject a loan.
     */
    public function rejectLoan(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/loans');
        }

        $loanModel = model(LoanModel::class);
        $loan      = $loanModel->find($id);

        if (! $loan || $loan['loan_status'] !== 'Pending') {
            session()->setFlashdata('error', 'Loan not found or is not pending.');
            return redirect()->to('/admin/loans');
        }

        $loanModel->update($id, ['loan_status' => 'Rejected']);

        session()->setFlashdata('success', 'Loan rejected.');
        return redirect()->to('/admin/loans');
    }

    // =========================================================================
    // Payments
    // =========================================================================

    public function payments(): string
    {
        $this->requireAdmin();

        $filterStatus = $this->request->getGet('status') ?: '';
        $db       = db_connect();

        $query = $db->table('loan_payments lp')
                    ->select("lp.payment_id AS id, lp.loan_id, lp.amount_paid, lp.payment_date, lp.payment_status AS status, l.loan_amount, ca.account_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name")
                    ->join('loans l', 'l.loan_id = lp.loan_id', 'left')
                    ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                    ->join('members m', 'm.member_id = ca.member_id', 'left')
                    ->orderBy('lp.created_at', 'DESC');

        if ($filterStatus !== '') {
            $query->where('lp.payment_status', $filterStatus);
        }

        $payments = $query->get()->getResultArray();

        return view('admin/payments', [
            'payments'     => $payments,
            'filterStatus' => $filterStatus,
        ]);
    }

    /**
     * POST: two-tier approval for a loan payment.
     * On final approval: mark installments Paid, check if loan fully paid,
     * write ledger entry.
     */
    public function approvePayment(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/payments');
        }

        $currentUserId = (int) session()->get('user_id');
        $db            = db_connect();
        $frModel       = model(FinancialRecordModel::class);
        $now           = date('Y-m-d H:i:s');

        $payment = $db->table('loan_payments')
                      ->where('payment_id', $id)
                      ->get()
                      ->getRowArray();

        if (! $payment) {
            session()->setFlashdata('error', 'Payment not found.');
            return redirect()->to('/admin/payments');
        }

        if ($payment['payment_status'] !== 'Pending') {
            session()->setFlashdata('error', 'Payment is no longer pending.');
            return redirect()->to('/admin/payments');
        }

        $frId = (int) $payment['financial_record_id'];
        $fr   = $frId ? $frModel->find($frId) : null;

        // If no FR attached yet, create one
        if (! $fr) {
            $loan = $db->table('loans')->where('loan_id', $payment['loan_id'])->get()->getRowArray();
            $frId = (int) $frModel->insert([
                'account_id'      => $loan['account_id'],
                'record_type'     => 'Loan Payment',
                'transaction_date'=> $payment['payment_date'],
                'amount'          => $payment['amount_paid'],
                'approval_status' => 'Pending',
                'processed_by'    => null,
                'reference_id'    => $payment['loan_id'],
            ]);
            $db->table('loan_payments')
               ->where('payment_id', $id)
               ->update(['financial_record_id' => $frId]);
            $fr = $frModel->find($frId);
        }

        $db->transStart();

        if (empty($fr['first_approved_by'])) {
            // Tier 1
            $db->table('financial_records')
               ->where('financial_record_id', $frId)
               ->where('first_approved_by IS NULL')
               ->update([
                   'first_approved_by'   => $currentUserId,
                   'first_approval_date' => $now,
                   'updated_at'          => $now,
               ]);

            if (! $db->affectedRows()) {
                $db->transRollback();
                session()->setFlashdata('error', 'Concurrent approval detected.');
                return redirect()->to('/admin/payments');
            }

            $db->transComplete();
            session()->setFlashdata('success', 'First approval recorded for payment.');
            return redirect()->to('/admin/payments');
        }

        if (! empty($fr['second_approved_by'])) {
            $db->transRollback();
            session()->setFlashdata('error', 'Payment already fully approved.');
            return redirect()->to('/admin/payments');
        }

        // Fix 4.7: second approver must differ
        if ((int) $fr['first_approved_by'] === $currentUserId) {
            $db->transRollback();
            session()->setFlashdata('error', 'You cannot provide both approvals for the same payment.');
            return redirect()->to('/admin/payments');
        }

        // Tier 2 — full approval
        $db->table('financial_records')
           ->where('financial_record_id', $frId)
           ->where('second_approved_by IS NULL')
           ->where('approval_status', 'Pending')
           ->update([
               'second_approved_by'   => $currentUserId,
               'second_approval_date' => $now,
               'approval_status'      => 'Approved',
               'processed_by'         => $currentUserId,
               'updated_at'           => $now,
           ]);

        if (! $db->affectedRows()) {
            $db->transRollback();
            session()->setFlashdata('error', 'Concurrent approval detected.');
            return redirect()->to('/admin/payments');
        }

        // Mark payment Approved
        $db->table('loan_payments')
           ->where('payment_id', $id)
           ->update(['payment_status' => 'Approved', 'updated_at' => $now]);

        // Mark allocated installments as Paid
        $allocatedInstallments = $db->table('loan_payment_installments')
                                    ->where('payment_id', $id)
                                    ->get()
                                    ->getResultArray();

        foreach ($allocatedInstallments as $lpi) {
            $db->table('loan_installments')
               ->where('installment_id', $lpi['installment_id'])
               ->update([
                   'amount_paid'    => $lpi['amount_applied'],
                   'payment_status' => 'Paid',
                   'payment_date'   => $payment['payment_date'],
                   'updated_at'     => $now,
               ]);
        }

        // Check if all installments for the loan are now Paid
        $unpaidCount = $db->table('loan_installments')
                          ->where('loan_id', $payment['loan_id'])
                          ->where('payment_status !=', 'Paid')
                          ->countAllResults();

        if ($unpaidCount === 0) {
            $db->table('loans')
               ->where('loan_id', $payment['loan_id'])
               ->update(['loan_status' => 'Paid', 'updated_at' => $now]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Database error during payment approval.');
            return redirect()->to('/admin/payments');
        }

        // Ledger entry
        $frFresh = $frModel->find($frId);
        try {
            $ledgerService = new LedgerService();
            $ledgerService->createEntry($frId, $frFresh);
        } catch (\Throwable $e) {
            log_message('error', 'LedgerService::createEntry failed for payment FR #' . $frId . ': ' . $e->getMessage());
        }

        session()->setFlashdata('success', 'Payment fully approved and ledger entry created.');
        return redirect()->to('/admin/payments');
    }

    /**
     * POST: reject a loan payment.
     */
    public function rejectPayment(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/payments');
        }

        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $payment = $db->table('loan_payments')->where('payment_id', $id)->get()->getRowArray();
        if (! $payment || $payment['payment_status'] !== 'Pending') {
            session()->setFlashdata('error', 'Payment not found or is not pending.');
            return redirect()->to('/admin/payments');
        }

        $db->table('loan_payments')
           ->where('payment_id', $id)
           ->update(['payment_status' => 'Rejected', 'updated_at' => $now]);

        if ($payment['financial_record_id']) {
            $frModel = model(FinancialRecordModel::class);
            $frModel->update($payment['financial_record_id'], ['approval_status' => 'Rejected', 'updated_at' => $now]);
        }

        session()->setFlashdata('success', 'Payment rejected.');
        return redirect()->to('/admin/payments');
    }

    // =========================================================================
    // Financial Records
    // =========================================================================

    public function financialRecords(): string
    {
        $this->requireAdmin();

        $db      = db_connect();
        $records = $db->table('financial_records fr')
                      ->select("fr.financial_record_id AS id, fr.record_type AS transaction_type, fr.amount, fr.transaction_date, fr.approval_status, fr.created_at, fr.first_approved_by AS approver1_id, fr.first_approval_date AS approved_at_1, fr.second_approved_by, fr.second_approval_date AS approved_at_2, ca.account_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, u1.username AS approver1_name, u2.username AS approver2_name, (CASE WHEN fr.second_approved_by IS NOT NULL THEN 2 WHEN fr.first_approved_by IS NOT NULL THEN 1 ELSE 0 END) AS approval_count")
                      ->join('cooperative_accounts ca', 'ca.account_id = fr.account_id', 'left')
                      ->join('members m', 'm.member_id = ca.member_id', 'left')
                      ->join('users u1', 'u1.user_id = fr.first_approved_by', 'left')
                      ->join('users u2', 'u2.user_id = fr.second_approved_by', 'left')
                      ->orderBy('fr.created_at', 'DESC')
                      ->get()
                      ->getResultArray();

        return view('admin/financial_records', ['financialRecords' => $records]);
    }

    /**
     * POST: two-tier approval for a financial record with ledger entry on final approval.
     */
    public function approveFinancialRecord(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/financial-records');
        }

        $currentUserId = (int) session()->get('user_id');
        $frModel       = model(FinancialRecordModel::class);
        $db            = db_connect();
        $now           = date('Y-m-d H:i:s');

        $fr = $frModel->find($id);
        if (! $fr) {
            session()->setFlashdata('error', 'Financial record not found.');
            return redirect()->to('/admin/financial-records');
        }

        if ($fr['approval_status'] !== 'Pending') {
            session()->setFlashdata('error', 'Record is no longer pending.');
            return redirect()->to('/admin/financial-records');
        }

        $db->transStart();

        if (empty($fr['first_approved_by'])) {
            // Tier 1
            $db->table('financial_records')
               ->where('financial_record_id', $id)
               ->where('first_approved_by IS NULL')
               ->update([
                   'first_approved_by'   => $currentUserId,
                   'first_approval_date' => $now,
                   'updated_at'          => $now,
               ]);

            if (! $db->affectedRows()) {
                $db->transRollback();
                session()->setFlashdata('error', 'Concurrent approval detected.');
                return redirect()->to('/admin/financial-records');
            }

            $db->transComplete();
            session()->setFlashdata('success', 'First approval recorded.');
            return redirect()->to('/admin/financial-records');
        }

        if (! empty($fr['second_approved_by'])) {
            $db->transRollback();
            session()->setFlashdata('error', 'Record has already been fully approved.');
            return redirect()->to('/admin/financial-records');
        }

        // Fix 4.7: second approver must differ
        if ((int) $fr['first_approved_by'] === $currentUserId) {
            $db->transRollback();
            session()->setFlashdata('error', 'You cannot provide both approvals for the same record.');
            return redirect()->to('/admin/financial-records');
        }

        // Tier 2
        $db->table('financial_records')
           ->where('financial_record_id', $id)
           ->where('second_approved_by IS NULL')
           ->where('approval_status', 'Pending')
           ->update([
               'second_approved_by'   => $currentUserId,
               'second_approval_date' => $now,
               'approval_status'      => 'Approved',
               'processed_by'         => $currentUserId,
               'updated_at'           => $now,
           ]);

        if (! $db->affectedRows()) {
            $db->transRollback();
            session()->setFlashdata('error', 'Concurrent approval detected.');
            return redirect()->to('/admin/financial-records');
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Database error during approval.');
            return redirect()->to('/admin/financial-records');
        }

        // Ledger entry
        $frFresh = $frModel->find($id);
        try {
            $ledgerService = new LedgerService();
            $ledgerService->createEntry($id, $frFresh);
        } catch (\Throwable $e) {
            log_message('error', 'LedgerService::createEntry failed for FR #' . $id . ': ' . $e->getMessage());
        }

        session()->setFlashdata('success', 'Financial record fully approved and ledger entry created.');
        return redirect()->to('/admin/financial-records');
    }

    /**
     * POST: reject a financial record.
     */
    public function rejectFinancialRecord(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/financial-records');
        }

        $frModel = model(FinancialRecordModel::class);
        $fr      = $frModel->find($id);

        if (! $fr || $fr['approval_status'] !== 'Pending') {
            session()->setFlashdata('error', 'Record not found or is not pending.');
            return redirect()->to('/admin/financial-records');
        }

        $frModel->update($id, ['approval_status' => 'Rejected', 'updated_at' => date('Y-m-d H:i:s')]);

        session()->setFlashdata('success', 'Financial record rejected.');
        return redirect()->to('/admin/financial-records');
    }

    // =========================================================================
    // Dividends
    // =========================================================================

    public function dividends(): string
    {
        $this->requireAdmin();

        $db        = db_connect();
        $dividends = $db->table('dividends d')
                        ->select('d.*, ca.account_number, m.first_name, m.last_name')
                        ->join('cooperative_accounts ca', 'ca.account_id = d.account_id', 'left')
                        ->join('members m', 'm.member_id = ca.member_id', 'left')
                        ->orderBy('d.distribution_year', 'DESC')
                        ->orderBy('d.created_at', 'DESC')
                        ->get()
                        ->getResultArray();

        return view('admin/dividends', ['dividends' => $dividends]);
    }

    /**
     * POST: calculate and record per-account dividend = profit_pool / eligible_accounts.
     */
    public function distributeDividends()
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/dividends');
        }

        $profitPool       = (float) $this->request->getPost('profit_pool');
        $distributionYear = (int)   $this->request->getPost('distribution_year');
        $distributionDate = $this->request->getPost('distribution_date') ?: date('Y-m-d');

        if ($profitPool <= 0 || $distributionYear < 2000) {
            session()->setFlashdata('error', 'Invalid profit pool or year.');
            return redirect()->to('/admin/dividends');
        }

        $db = db_connect();

        // Get eligible (Active) accounts
        $accounts = $db->table('cooperative_accounts')
                       ->where('account_status', 'Active')
                       ->get()
                       ->getResultArray();

        $eligibleCount = count($accounts);
        if ($eligibleCount === 0) {
            session()->setFlashdata('error', 'No eligible accounts found.');
            return redirect()->to('/admin/dividends');
        }

        $dividendPerAccount = round($profitPool / $eligibleCount, 2);
        $now                = date('Y-m-d H:i:s');
        $currentUserId      = (int) session()->get('user_id');

        $db->transStart();

        $insertedFrIds = [];

        foreach ($accounts as $account) {
            $db->table('dividends')->insert([
                'account_id'             => $account['account_id'],
                'distribution_year'      => $distributionYear,
                'profit_pool'            => $profitPool,
                'eligible_account_count' => $eligibleCount,
                'dividend_per_account'   => $dividendPerAccount,
                'dividend_amount'        => $dividendPerAccount,
                'distribution_date'      => $distributionDate,
                'dividend_status'        => 'Distributed',
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            // Create a financial_record for this dividend so it enters the ledger
            $db->table('financial_records')->insert([
                'account_id'          => $account['account_id'],
                'record_type'         => 'Dividend',
                'transaction_date'    => $distributionDate,
                'amount'              => $dividendPerAccount,
                'approval_status'     => 'Approved',
                'processed_by'        => $currentUserId,
                'first_approved_by'   => $currentUserId,
                'first_approval_date' => $now,
                'second_approved_by'  => $currentUserId,
                'second_approval_date'=> $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $insertedFrIds[] = [
                'fr_id'      => (int) $db->insertID(),
                'account_id' => (int) $account['account_id'],
            ];
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Failed to distribute dividends.');
            return redirect()->to('/admin/dividends');
        }

        // Append ledger entries for each dividend financial record
        $frModel       = model(FinancialRecordModel::class);
        $ledgerService = new LedgerService();
        foreach ($insertedFrIds as $item) {
            $frFresh = $frModel->find($item['fr_id']);
            if (! $frFresh) {
                continue;
            }
            try {
                $ledgerService->createEntry($item['fr_id'], $frFresh);
            } catch (\Throwable $e) {
                log_message('error', 'LedgerService::createEntry failed for Dividend FR #' . $item['fr_id'] . ': ' . $e->getMessage());
            }
        }

        session()->setFlashdata('success', "Dividends distributed: {$eligibleCount} accounts × PHP {$dividendPerAccount}.");
        return redirect()->to('/admin/dividends');
    }

    // =========================================================================
    // Blockchain Ledger
    // =========================================================================

    public function ledger(): string
    {
        $this->requireAdmin();

        $db      = db_connect();
        $ledgerEntries = $db->table('blockchain_ledger bl')
                      ->select('bl.ledger_id, bl.financial_record_id, bl.ledger_sequence AS sequence, bl.transaction_hash AS hash, bl.previous_hash, bl.recorded_at, bl.verification_status AS status, fr.record_type, fr.amount, fr.approval_status')
                      ->join('financial_records fr', 'fr.financial_record_id = bl.financial_record_id', 'left')
                      ->orderBy('bl.ledger_sequence', 'ASC')
                      ->get()
                      ->getResultArray();

        return view('admin/ledger', ['ledgerEntries' => $ledgerEntries]);
    }

    /**
     * POST: run end-to-end ledger verification (Fix 4.6).
     * Returns JSON result.
     */
    public function verifyLedger()
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/ledger');
        }

        $ledgerService = new LedgerService();
        $result        = $ledgerService->verifyLedger();

        // If the caller accepts JSON, return JSON; otherwise flash and redirect
        if (strpos($this->request->getHeaderLine('Accept'), 'application/json') !== false) {
            return $this->response->setJSON($result);
        }

        $message = "Ledger verification {$result['result']}: "
                 . "{$result['valid']} valid, {$result['tampered']} tampered "
                 . "out of {$result['checked']} checked.";

        $tamperedIds = [];
        if (!empty($result['first_failure'])) {
            $tamperedIds = [$result['first_failure']['sequence'] ?? '?'];
        }

        session()->setFlashdata('verification_result', [
            'status'      => $result['result'] === 'PASS' ? 'valid' : 'tampered',
            'message'     => $message,
            'tampered_ids' => $tamperedIds,
        ]);
        return redirect()->to('/admin/ledger');
    }

    // =========================================================================
    // Analytics
    // =========================================================================

    public function analytics(): string
    {
        $this->requireAdmin();
        return view('admin/analytics');
    }

    // =========================================================================
    // Reports
    // =========================================================================

    public function reports(): string
    {
        $this->requireAdmin();

        $db = db_connect();

        $totalMembers       = $db->table('members')->countAllResults();
        $activeMembers      = $db->table('members')->where('membership_status', 'Active')->countAllResults();
        $totalContributions = (float) ($db->table('financial_records')
            ->where('record_type', 'Contribution')->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);
        $activeLoans        = $db->table('loans')->where('loan_status', 'Active')->countAllResults();
        $totalLoanAmount    = (float) ($db->table('financial_records')
            ->where('record_type', 'Loan Disbursement')->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);
        $totalPayments      = (float) ($db->table('financial_records')
            ->where('record_type', 'Loan Payment')->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);
        $totalDividends     = (float) ($db->table('financial_records')
            ->where('record_type', 'Dividend')->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);
        $ledgerCount        = $db->table('blockchain_ledger')->countAllResults();
        $tamperedCount      = $db->table('blockchain_ledger')->where('verification_status', 'Tampered')->countAllResults();

        return view('admin/reports', [
            'totalMembers'       => $totalMembers,
            'activeMembers'      => $activeMembers,
            'totalContributions' => $totalContributions,
            'activeLoans'        => $activeLoans,
            'totalLoanAmount'    => $totalLoanAmount,
            'totalPayments'      => $totalPayments,
            'totalDividends'     => $totalDividends,
            'ledgerCount'        => $ledgerCount,
            'tamperedCount'      => $tamperedCount,
        ]);
    }

    /**
     * GET: return JSON data for Chart.js using AnalyticsService.
     */
    public function analyticsData()
    {
        $this->requireAdmin();

        $analyticsService = new AnalyticsService();
        $months           = (int) ($this->request->getGet('months') ?: 12);
        $forecastMonths   = (int) ($this->request->getGet('forecast') ?: 3);

        $historical = $analyticsService->getEquityGrowthData($months);
        $forecast   = $analyticsService->predictNextMonths($historical, $forecastMonths);

        $labels         = array_column($historical, 'label');
        $actual         = array_map('floatval', array_column($historical, 'total'));
        $forecastLabels = array_column($forecast, 'label');
        $forecastValues = array_map('floatval', array_column($forecast, 'total'));

        $db = db_connect();
        $totalMembers       = $db->table('members')->countAllResults();
        $totalContributions = (float) ($db->table('financial_records')
            ->where('record_type', 'Contribution')
            ->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);
        $activeLoans        = $db->table('financial_records')
            ->where('record_type', 'Loan')
            ->where('approval_status', 'Approved')
            ->countAllResults();
        $currentEquity      = (float) ($db->table('financial_records')
            ->whereIn('record_type', ['Contribution', 'Dividend'])
            ->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);

        return $this->response->setJSON([
            'labels'             => $labels,
            'actual'             => $actual,
            'forecast'           => $forecastValues,
            'forecastLabels'     => $forecastLabels,
            'forecastStartIndex' => count($actual),
            'stats'              => [
                'total_members'       => $totalMembers,
                'total_contributions' => $totalContributions,
                'active_loans'        => $activeLoans,
                'current_equity'      => $currentEquity,
            ],
            'note' => 'Forecast values are ESTIMATES / PREDICTIONS based on historical trends.',
        ]);
    }
}
