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

    private const PER_PAGE = 15;

    /**
     * Run one page of a query. Returns the rows and the page numbers for partials/pager.
     */
    private function paginate(\CodeIgniter\Database\BaseBuilder $query): array
    {
        $total = $query->countAllResults(false);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($pages, max(1, (int) $this->request->getGet('page')));
        $rows  = $query->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return [$rows, [
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
            'from'  => $total ? ($page - 1) * self::PER_PAGE + 1 : 0,
            'to'    => ($page - 1) * self::PER_PAGE + count($rows),
        ]];
    }

    /**
     * Match a member by name, member #, account # or the record's own ID.
     */
    private function searchMemberColumns(\CodeIgniter\Database\BaseBuilder $query, string $search, string $idColumn): void
    {
        if ($search === '') {
            return;
        }

        $query->groupStart()
              ->like("CONCAT(m.first_name, ' ', m.last_name)", $search, 'both', null, true)
              ->orLike('m.member_number', $search, 'both', null, true)
              ->orLike('ca.account_number', $search, 'both', null, true);
        if (ctype_digit(ltrim($search, '#'))) {
            $query->orWhere($idColumn, (int) ltrim($search, '#'));
        }
        $query->groupEnd();
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

        return view('admin/dashboard', [
            'needsDbUpdate'        => \App\Libraries\DatabaseUpdates::needsUpdate(),
            'totalMembers'         => $db->table('members')->countAllResults(),
            'totalAccounts'        => $db->table('cooperative_accounts')->countAllResults(),
            'pendingContributions' => $pendingContributions,
            'pendingLoans'         => $pendingLoans,
            'pendingPayments'      => $pendingPayments,
            'overview'             => (new \App\Services\DashboardService())->build(null),
        ]);
    }

    // =========================================================================
    // Members
    // =========================================================================

    public function members(): string
    {
        $this->requireAdmin();

        $db     = db_connect();
        $search = trim((string) $this->request->getGet('q'));

        $query = $db->table('members m')
                    ->select('m.member_id AS id, m.member_number, m.first_name, m.last_name, m.membership_date, m.membership_status AS status, u.username, u.email, u.account_status AS user_status')
                    ->join('users u', 'u.user_id = m.user_id', 'left')
                    ->orderBy('m.member_id', 'ASC');

        if ($search !== '') {
            $like = $db->escape('%' . $db->escapeLikeString($search) . '%') . " ESCAPE '!'";
            $query->groupStart()
                  ->where("m.member_number LIKE {$like}", null, false)
                  ->orWhere("CAST(m.member_id AS CHAR) = " . $db->escape($search), null, false)
                  ->orWhere("CONCAT(m.first_name, ' ', m.last_name) LIKE {$like}", null, false)
                  ->orWhere("u.username LIKE {$like}", null, false)
                  ->orWhere("u.email LIKE {$like}", null, false)
                  ->orWhere("m.member_id IN (SELECT member_id FROM cooperative_accounts WHERE account_number LIKE {$like})", null, false)
                  ->groupEnd();
        }

        $members = $query->get()->getResultArray();

        $accountModel     = model(CooperativeAccountModel::class);
        $accountsByMember = [];
        $accounts = $db->table('cooperative_accounts')
                       ->orderBy('account_id', 'ASC')
                       ->get()
                       ->getResultArray();
        foreach ($accounts as $acc) {
            $acc['standing'] = $accountModel->getStanding($acc);
            $accountsByMember[$acc['member_id']][] = $acc;
        }
        foreach ($members as &$member) {
            $member['accounts'] = $accountsByMember[$member['id']] ?? [];
        }
        unset($member);

        return view('admin/members', ['members' => $members, 'search' => $search]);
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

        $opening = $this->openingContribution();
        if (is_string($opening)) {
            session()->setFlashdata('error', $opening);
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

            $password = $this->request->getPost('password');
            if (strlen($password) < 8) {
                $db->transRollback();
                session()->setFlashdata('error', 'Password must be at least 8 characters.');
                return redirect()->to('/admin/members');
            }
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
            $accountType = $this->request->getPost('account_type');
            if (! in_array($accountType, CooperativeAccountModel::ACCOUNT_TYPES, true)) {
                $accountType = 'Basic';
            }
            $accountModel  = model(CooperativeAccountModel::class);
            $accountNumber = $accountModel->openAccount((int) $memberId, $accountType);
            $accountModel->recordOpeningContribution($accountNumber, $opening, (int) session()->get('user_id'));

            $db->transComplete();

            if (! $db->transStatus()) {
                throw new \RuntimeException('Transaction failed during member creation.');
            }

            session()->setFlashdata('success', "Member {$memberNumber} created with account {$accountNumber}. "
                . 'The opening contribution of PHP ' . number_format($opening, 2) . ' is waiting for approval in Contributions.');
        } catch (\Throwable $e) {
            $db->transRollback();
            session()->setFlashdata('error', 'Failed to add member: ' . $e->getMessage());
        }

        return redirect()->to('/admin/members');
    }

    /**
     * The opening contribution typed on the Add Member / Add Account form,
     * or an error message when it is outside PHP 300 – 3,600.
     */
    private function openingContribution(): float|string
    {
        $amount = round((float) $this->request->getPost('opening_contribution'), 2);
        $min    = CooperativeAccountModel::OPENING_MIN;
        $max    = CooperativeAccountModel::OPENING_MAX;

        if ($amount < $min || $amount > $max) {
            return 'The opening contribution must be between PHP ' . number_format($min, 2) . ' and PHP ' . number_format($max, 2) . '.';
        }

        return $amount;
    }

    /**
     * POST: open an additional cooperative account for an existing member.
     */
    public function addAccount(int $memberId)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $member = model(MemberModel::class)->find($memberId);
        if (! $member) {
            session()->setFlashdata('error', 'Member not found.');
            return redirect()->to('/admin/members');
        }
        if ($member['membership_status'] !== 'Active') {
            session()->setFlashdata('error', 'Reactivate this member before opening a new account.');
            return redirect()->to('/admin/members');
        }

        $accountType = $this->request->getPost('account_type');
        if (! in_array($accountType, CooperativeAccountModel::ACCOUNT_TYPES, true)) {
            session()->setFlashdata('error', 'Please choose a valid account type.');
            return redirect()->to('/admin/members');
        }

        $opening = $this->openingContribution();
        if (is_string($opening)) {
            session()->setFlashdata('error', $opening);
            return redirect()->to('/admin/members');
        }

        $db = db_connect();
        $db->transStart();
        $accountModel  = model(CooperativeAccountModel::class);
        $accountNumber = $accountModel->openAccount($memberId, $accountType);
        $accountModel->recordOpeningContribution($accountNumber, $opening, (int) session()->get('user_id'));
        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Could not open the account. Please try again.');
            return redirect()->to('/admin/members');
        }

        session()->setFlashdata('success', "{$accountType} account {$accountNumber} added for {$member['first_name']} {$member['last_name']}. "
            . 'The opening contribution of PHP ' . number_format($opening, 2) . ' is waiting for approval in Contributions.');
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
        if (! $memberModel->find($id)) {
            session()->setFlashdata('error', 'Member not found.');
            return redirect()->to('/admin/members');
        }

        $memberModel->update($id, ['membership_status' => 'Inactive']);
        session()->setFlashdata('success', 'Member deactivated.');
        return redirect()->to('/admin/members');
    }

    /**
     * POST: re-activate a previously deactivated member.
     */
    public function reactivateMember(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $memberModel = model(MemberModel::class);
        if (! $memberModel->find($id)) {
            session()->setFlashdata('error', 'Member not found.');
            return redirect()->to('/admin/members');
        }

        $memberModel->update($id, ['membership_status' => 'Active']);
        session()->setFlashdata('success', 'Member reactivated.');
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

        $search = trim((string) $this->request->getGet('q'));
        $this->searchMemberColumns($query, $search, 'c.contribution_id');
        $query->orderBy('c.contribution_id', 'DESC');

        [$contributions, $page] = $this->paginate($query);

        return view('admin/contributions', [
            'contributions' => $contributions,
            'filterStatus'  => $filterStatus,
            'search'        => $search,
            'page'          => $page,
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

            $this->applyApprovedRecord($fr);

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
                    ->select("l.loan_id AS id, l.loan_amount, l.interest_rate, l.interest_amount, l.loan_term_months AS term_months, l.loan_status AS status, l.application_date AS applied_date, l.approval_date, l.release_date, l.account_id, ca.account_number, ca.account_type, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, (SELECT COALESCE(SUM(li.amount_paid), 0) FROM loan_installments li WHERE li.loan_id = l.loan_id) AS total_paid, (l.loan_amount + l.interest_amount) AS total_payable", false)
                    ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                    ->join('members m', 'm.member_id = ca.member_id', 'left')
                    ->orderBy('l.application_date', 'DESC');

        if ($filterStatus !== '') {
            $query->where('l.loan_status', $filterStatus);
        }

        $search = trim((string) $this->request->getGet('q'));
        $this->searchMemberColumns($query, $search, 'l.loan_id');
        $query->orderBy('l.loan_id', 'DESC');

        [$loans, $page] = $this->paginate($query);

        return view('admin/loans', [
            'loans'        => $loans,
            'filterStatus' => $filterStatus,
            'search'       => $search,
            'page'         => $page,
        ]);
    }

    /**
     * POST: approve a loan and generate installments via LoanService.
     */
    /**
     * GET: a loan's month-by-month schedule with amounts paid and balance.
     */
    public function loanSchedule(int $id): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $this->requireAdmin();

        $loan = db_connect()->table('loans l')
                            ->select("l.*, ca.account_number, ca.account_type, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name", false)
                            ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                            ->join('members m', 'm.member_id = ca.member_id', 'left')
                            ->where('l.loan_id', $id)
                            ->get()->getRowArray();

        if (! $loan) {
            if ($this->request->isAJAX()) {
                return '<div class="alert alert-danger mb-0">Loan not found.</div>';
            }
            session()->setFlashdata('error', 'Loan not found.');
            return redirect()->to('/admin/loans');
        }

        $data = ['loan' => $loan, 'schedule' => (new LoanService())->schedule($id)];

        // The Loans page opens the schedule in a pop-up window and only needs its contents.
        return view($this->request->isAJAX() ? 'admin/loan_schedule_body' : 'admin/loan_schedule', $data);
    }

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
                'payment_status' => 'Upcoming',
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
                    ->select("lp.payment_id AS id, lp.loan_id, lp.amount_paid, lp.payment_date, lp.payment_status AS status, lp.financial_record_id, l.loan_amount, l.loan_status, m.member_number, ca.account_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, (SELECT COALESCE(SUM(li.amount_paid), 0) FROM loan_installments li WHERE li.loan_id = l.loan_id) AS total_paid, (l.loan_amount + l.interest_amount) AS total_payable, fr.first_approved_by, u1.username AS approver1_name, (SELECT GROUP_CONCAT(CONCAT('Month ', li2.installment_number, ': ₱', FORMAT(lpi.amount_applied, 2)) ORDER BY li2.installment_number SEPARATOR ', ') FROM loan_payment_installments lpi JOIN loan_installments li2 ON li2.installment_id = lpi.installment_id WHERE lpi.payment_id = lp.payment_id) AS months", false)
                    ->join('financial_records fr', 'fr.financial_record_id = lp.financial_record_id', 'left')
                    ->join('users u1', 'u1.user_id = fr.first_approved_by', 'left')
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

        if ($problem = $this->paymentOverBalance($payment)) {
            session()->setFlashdata('error', $problem);
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

        $this->applyLoanPayment($payment);

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
     * Once a financial record has both approvals, bring its source row in line:
     * contributions become Approved (and may complete an account's joining),
     * loan payments are applied to their installments.
     */
    private function applyApprovedRecord(array $fr): void
    {
        $db    = db_connect();
        $frId  = (int) $fr['financial_record_id'];

        if ($fr['record_type'] === 'Contribution') {
            $db->table('contributions')
               ->where('financial_record_id', $frId)
               ->update(['payment_status' => 'Approved', 'updated_at' => date('Y-m-d H:i:s')]);
            model(CooperativeAccountModel::class)->activateIfComplete((int) $fr['account_id']);
        } elseif ($fr['record_type'] === 'Loan Payment') {
            $payment = $db->table('loan_payments')->where('financial_record_id', $frId)->get()->getRowArray();
            if ($payment && $payment['payment_status'] === 'Pending') {
                $this->applyLoanPayment($payment);
            }
        }
    }

    /**
     * A payment larger than what is left on its loan cannot be applied in
     * full (for example an old duplicate), so it must be rejected instead.
     */
    private function paymentOverBalance(array $payment): ?string
    {
        $balance = (new LoanService())->schedule((int) $payment['loan_id'])['summary']['balance'];
        if ((float) $payment['amount_paid'] > $balance + 0.004) {
            return 'Payment #' . $payment['payment_id'] . ' (PHP ' . number_format((float) $payment['amount_paid'], 2)
                 . ') is more than the remaining balance of loan #' . $payment['loan_id']
                 . ' (PHP ' . number_format($balance, 2) . '). Please reject it.';
        }
        return null;
    }

    /**
     * Mark an approved payment and apply each month's amount to the loan
     * schedule. See LoanService::applyPayment().
     */
    private function applyLoanPayment(array $payment): void
    {
        $db = db_connect();
        $db->table('loan_payments')
           ->where('payment_id', $payment['payment_id'])
           ->update(['payment_status' => 'Approved', 'updated_at' => date('Y-m-d H:i:s')]);

        $allocations = [];
        foreach ($db->table('loan_payment_installments')->where('payment_id', $payment['payment_id'])->get()->getResultArray() as $row) {
            $allocations[(int) $row['installment_id']] = ($allocations[(int) $row['installment_id']] ?? 0) + (float) $row['amount_applied'];
        }

        (new LoanService())->applyPayment((int) $payment['loan_id'], $allocations, $payment['payment_date']);
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
                      ->select("fr.financial_record_id AS id, fr.record_type AS transaction_type, fr.amount, fr.transaction_date, fr.approval_status, fr.created_at, fr.first_approved_by AS approver1_id, fr.first_approval_date AS approved_at_1, fr.second_approved_by, fr.second_approval_date AS approved_at_2, ca.account_number, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, u1.username AS approver1_name, u2.username AS approver2_name, (CASE WHEN fr.second_approved_by IS NOT NULL THEN 2 WHEN fr.first_approved_by IS NOT NULL THEN 1 ELSE 0 END) AS approval_count")
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

        if ($fr['record_type'] === 'Loan Payment') {
            $payment = $db->table('loan_payments')->where('financial_record_id', $id)->get()->getRowArray();
            if ($payment && ($problem = $this->paymentOverBalance($payment))) {
                session()->setFlashdata('error', $problem);
                return redirect()->to('/admin/financial-records');
            }
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

        $this->applyApprovedRecord($fr);

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

        $now = date('Y-m-d H:i:s');
        $frModel->update($id, ['approval_status' => 'Rejected', 'updated_at' => $now]);

        $source = ['Contribution' => 'contributions', 'Loan Payment' => 'loan_payments'][$fr['record_type']] ?? null;
        if ($source) {
            db_connect()->table($source)
                        ->where('financial_record_id', $id)
                        ->where('payment_status', 'Pending')
                        ->update(['payment_status' => 'Rejected', 'updated_at' => $now]);
        }

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

        $year = (int) ($this->request->getGet('year') ?: date('Y'));

        return view('admin/dividends', [
            'dividends' => $dividends,
            'year'      => $year,
            'plan'      => $this->planDividends($year),
        ]);
    }

    /**
     * Profit pool = interest earned on loans approved in the year. Every Active
     * account gets an equal share, Pioneer accounts get PIONEER_DIVIDEND_BONUS %
     * more than a Basic share, and the shares add up to the whole pool.
     */
    private function planDividends(int $year): array
    {
        $db = db_connect();

        $profitPool = (float) ($db->table('loans')
            ->selectSum('interest_amount')
            ->whereIn('loan_status', ['Active', 'Paid'])
            ->where('YEAR(approval_date)', $year)
            ->get()->getRowArray()['interest_amount'] ?? 0);

        $accounts = $db->table('cooperative_accounts')
                       ->where('account_status', 'Active')
                       ->get()->getResultArray();

        $pioneerWeight = 1 + CooperativeAccountModel::PIONEER_DIVIDEND_BONUS / 100;
        $pioneers      = count(array_filter($accounts, fn($a) => $a['account_type'] === 'Pioneer'));
        $basics        = count($accounts) - $pioneers;
        $totalWeight   = $basics + $pioneers * $pioneerWeight;

        // Round down to the centavo so the shares never add up to more than the pool.
        $basicShare   = $totalWeight > 0 ? floor(round($profitPool / $totalWeight * 100, 6)) / 100 : 0.0;
        $pioneerShare = floor(round($basicShare * $pioneerWeight * 100, 6)) / 100;

        return [
            'profit_pool'   => round($profitPool, 2),
            'accounts'      => $accounts,
            'basic_count'   => $basics,
            'pioneer_count' => $pioneers,
            'basic_share'   => $basicShare,
            'pioneer_share' => $pioneerShare,
        ];
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

        $distributionYear = (int) $this->request->getPost('distribution_year');
        $distributionDate = $this->request->getPost('distribution_date') ?: date('Y-m-d');

        if ($distributionYear < 2000) {
            session()->setFlashdata('error', 'Invalid year.');
            return redirect()->to('/admin/dividends');
        }

        $plan       = $this->planDividends($distributionYear);
        $profitPool = $plan['profit_pool'];

        if ($profitPool <= 0) {
            session()->setFlashdata('error', "There is no loan interest earned in {$distributionYear} to distribute.");
            return redirect()->to('/admin/dividends?year=' . $distributionYear);
        }

        $db = db_connect();

        // Prevent duplicate distribution for the same year
        $existingDividend = $db->table('dividends')
                               ->where('distribution_year', $distributionYear)
                               ->limit(1)->get()->getRowArray();
        if ($existingDividend) {
            session()->setFlashdata('error', "Dividends for year {$distributionYear} have already been distributed.");
            return redirect()->to('/admin/dividends');
        }

        $accounts      = $plan['accounts'];
        $eligibleCount = count($accounts);
        if ($eligibleCount === 0) {
            session()->setFlashdata('error', 'No eligible accounts found.');
            return redirect()->to('/admin/dividends');
        }

        $dividendPerAccount = $plan['basic_share'];
        $now                = date('Y-m-d H:i:s');
        $currentUserId      = (int) session()->get('user_id');

        $db->transStart();

        $insertedFrIds = [];

        foreach ($accounts as $account) {
            $amount = $account['account_type'] === 'Pioneer' ? $plan['pioneer_share'] : $plan['basic_share'];

            // Create financial_record first so we can link it to the dividend row
            $db->table('financial_records')->insert([
                'account_id'          => $account['account_id'],
                'record_type'         => 'Dividend',
                'transaction_date'    => $distributionDate,
                'amount'              => $amount,
                'approval_status'     => 'Approved',
                'processed_by'        => $currentUserId,
                'first_approved_by'   => $currentUserId,
                'first_approval_date' => $now,
                'second_approved_by'  => $currentUserId,
                'second_approval_date'=> $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $frId = (int) $db->insertID();

            $db->table('dividends')->insert([
                'account_id'             => $account['account_id'],
                'distribution_year'      => $distributionYear,
                'profit_pool'            => $profitPool,
                'eligible_account_count' => $eligibleCount,
                'dividend_per_account'   => $dividendPerAccount,
                'dividend_amount'        => $amount,
                'distribution_date'      => $distributionDate,
                'dividend_status'        => 'Distributed',
                'financial_record_id'    => $frId,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            $insertedFrIds[] = [
                'fr_id'      => $frId,
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

        session()->setFlashdata('success', "Dividends of PHP " . number_format($profitPool, 2) . " distributed to {$eligibleCount} accounts (Basic PHP {$plan['basic_share']}, Pioneer PHP {$plan['pioneer_share']}).");
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

        return $this->response->setJSON($analyticsService->chartPayload($months, $forecastMonths));
    }
}
