<?php

namespace App\Controllers;

use App\Models\MemberModel;
use App\Models\CooperativeAccountModel;
use App\Models\ContributionModel;
use App\Models\FinancialRecordModel;
use App\Models\LoanModel;
use App\Models\LoanInstallmentModel;
use App\Services\LoanService;

/**
 * Member controller — self-service portal for cooperative members.
 *
 * All methods call requireMember() for server-side role verification (Fix 4.9).
 * State-changing actions are POST-only (Fix 4.3).
 */
class Member extends BaseController
{

    // -------------------------------------------------------------------------
    // Auth guard
    // -------------------------------------------------------------------------

    /**
     * Verify that the current session belongs to an authenticated member.
     * Returns the session member_id for convenience.
     */
    private function requireMember(): int
    {
        $session = session();

        if (! $session->get('logged_in') || strtolower((string) $session->get('role')) !== 'member') {
            $session->destroy();
            redirect()->to('/login')->send();
            exit;
        }

        $memberId = (int) $session->get('member_id');
        if ($memberId < 1) {
            $session->destroy();
            redirect()->to('/login')->send();
            exit;
        }

        return $memberId;
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboard(): string
    {
        $memberId = $this->requireMember();
        $db       = db_connect();

        // Member's cooperative accounts
        $accounts = $db->table('cooperative_accounts')
                       ->where('member_id', $memberId)
                       ->get()
                       ->getResultArray();

        $accountIds = array_column($accounts, 'account_id');

        $accountModel = model(CooperativeAccountModel::class);
        foreach ($accounts as &$acc) {
            $acc['standing'] = $accountModel->getStanding($acc);
        }
        unset($acc);

        return view('member/dashboard', [
            'accounts' => $accounts,
            'overview' => (new \App\Services\DashboardService())->build(array_map('intval', $accountIds)),
        ]);
    }

    // =========================================================================
    // Contributions
    // =========================================================================

    public function contributions(): string
    {
        $memberId   = $this->requireMember();
        $db         = db_connect();

        $accounts   = $db->table('cooperative_accounts')
                         ->where('member_id', $memberId)
                         ->get()
                         ->getResultArray();
        $accountIds = array_column($accounts, 'account_id');

        $contributions = [];
        if ($accountIds) {
            $contributions = $db->table('contributions c')
                                ->select('c.contribution_id AS id, c.contribution_period, c.amount, c.payment_date, c.payment_status, c.financial_record_id, c.created_at, ca.account_number, fr.approval_status')
                                ->join('cooperative_accounts ca', 'ca.account_id = c.account_id', 'left')
                                ->join('financial_records fr', 'fr.financial_record_id = c.financial_record_id', 'left')
                                ->whereIn('c.account_id', $accountIds)
                                ->orderBy('c.created_at', 'DESC')
                                ->get()
                                ->getResultArray();
        }

        $totalPaid    = (float) array_sum(array_column(
            array_filter($contributions, fn($c) => ($c['approval_status'] ?? '') === 'Approved'),
            'amount'
        ));
        $pendingCount = count(array_filter($contributions, fn($c) => in_array($c['approval_status'] ?? 'Pending', ['Pending', ''])));

        $accountModel = model(CooperativeAccountModel::class);
        foreach ($accounts as &$acc) {
            $acc['standing'] = $accountModel->getStanding($acc);
        }
        unset($acc);

        return view('member/contributions', [
            'contributions' => $contributions,
            'accounts'      => $accounts,
            'totalPaid'     => $totalPaid,
            'pendingCount'  => $pendingCount,
        ]);
    }

    /**
     * POST: submit a new contribution for the member's own account.
     * Creates contribution + financial_record (status=Pending).
     * Member may only submit for accounts they own.
     */
    public function submitContribution()
    {
        $memberId = $this->requireMember();

        if (! $this->request->is('post')) {
            return redirect()->to('/member/contributions');
        }

        $accountId = (int) $this->request->getPost('account_id');
        $period    = (string) $this->request->getPost('contribution_period');  // YYYY-MM
        $amount    = round((float) $this->request->getPost('amount'), 2);

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            session()->setFlashdata('error', 'Invalid contribution period format.');
            return redirect()->to('/member/contributions');
        }

        // Ownership check: the account must belong to this member
        $db      = db_connect();
        $account = $db->table('cooperative_accounts')
                      ->where('account_id', $accountId)
                      ->where('member_id', $memberId)
                      ->get()
                      ->getRowArray();

        if (! $account) {
            session()->setFlashdata('error', 'Invalid account selection.');
            return redirect()->to('/member/contributions');
        }

        if (! in_array($account['account_status'], ['Active', 'Pending'], true)) {
            session()->setFlashdata('error', 'This account is not open for contributions.');
            return redirect()->to('/member/contributions');
        }

        if ($amount <= 0) {
            session()->setFlashdata('error', 'Amount must be greater than zero.');
            return redirect()->to('/member/contributions');
        }

        $now    = date('Y-m-d H:i:s');
        $today  = date('Y-m-d');
        $userId = (int) session()->get('user_id');

        $db->transStart();

        // Create financial record (Pending)
        $frModel = model(FinancialRecordModel::class);
        $frId    = $frModel->insert([
            'account_id'      => $accountId,
            'record_type'     => 'Contribution',
            'transaction_date'=> $today,
            'amount'          => $amount,
            'approval_status' => 'Pending',
            'processed_by'    => $userId,
            'reference_id'    => null,
        ]);

        if (! $frId) {
            $db->transRollback();
            session()->setFlashdata('error', 'Failed to create financial record: ' . implode(' ', $frModel->errors()));
            return redirect()->to('/member/contributions');
        }

        // Create contribution record
        $db->table('contributions')->insert([
            'account_id'          => $accountId,
            'contribution_period' => $period ?: date('Y-m'),
            'amount'              => $amount,
            'payment_date'        => $today,
            'payment_status'      => 'Pending',
            'financial_record_id' => $frId,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Failed to submit contribution. Please try again.');
            return redirect()->to('/member/contributions');
        }

        session()->setFlashdata('success', 'Contribution submitted and pending approval.');
        return redirect()->to('/member/contributions');
    }

    // =========================================================================
    // Loans
    // =========================================================================

    public function loans(): string
    {
        $memberId   = $this->requireMember();
        $db         = db_connect();

        $accounts   = $db->table('cooperative_accounts')
                         ->where('member_id', $memberId)
                         ->get()
                         ->getResultArray();
        $accountIds = array_column($accounts, 'account_id');

        $loans = [];
        if ($accountIds) {
            $loans = $db->table('loans l')
                        ->select('l.loan_id AS id, l.loan_amount, l.interest_rate, l.interest_amount, l.loan_term_months AS term_months, l.application_date AS applied_date, l.loan_status AS status, l.account_id, ca.account_number')
                        ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                        ->whereIn('l.account_id', $accountIds)
                        ->orderBy('l.application_date', 'DESC')
                        ->get()
                        ->getResultArray();

            foreach ($loans as &$loan) {
                $balRow = $db->table('loan_installments')
                             ->select('COALESCE(SUM(amount_due - amount_paid), 0) AS balance, COUNT(*) AS n', false)
                             ->where('loan_id', $loan['id'])
                             ->get()->getRowArray();
                $loan['outstanding_balance'] = (int) $balRow['n'] > 0
                    ? (float) $balRow['balance']
                    : (float) $loan['loan_amount'] + (float) $loan['interest_amount'];

                $firstInst = $db->table('loan_installments')
                                ->where('loan_id', $loan['id'])
                                ->orderBy('installment_number', 'ASC')
                                ->limit(1)->get()->getRowArray();
                $loan['monthly_payment'] = $firstInst ? (float)$firstInst['amount_due'] : 0.0;

                if (in_array($loan['status'], ['Active', 'Approved', 'Paid'], true)) {
                    $loan['panel'] = $this->loanPanel((int) $loan['id'], $loan['status']);
                }
            }
            unset($loan);
        }

        return view('member/loans', [
            'loans'    => $loans,
            'accounts' => $accounts,
        ]);
    }

    /**
     * POST: apply for a loan.
     * Validates that the requested amount does not exceed the member's max loan limit.
     */
    public function applyLoan()
    {
        $memberId = $this->requireMember();

        if (! $this->request->is('post')) {
            return redirect()->to('/member/loans');
        }

        $accountId    = (int) $this->request->getPost('account_id');
        $loanAmount   = (float) $this->request->getPost('loan_amount');
        $termMonths   = (int) $this->request->getPost('loan_term_months');

        $allowedTerms = [3, 6, 12, 18, 24];
        if (! in_array($termMonths, $allowedTerms, true)) {
            session()->setFlashdata('error', 'Invalid loan term. Please select a valid option.');
            return redirect()->to('/member/loans');
        }

        // Ownership check
        $db      = db_connect();
        $account = $db->table('cooperative_accounts')
                      ->where('account_id', $accountId)
                      ->where('member_id', $memberId)
                      ->get()
                      ->getRowArray();

        if (! $account) {
            session()->setFlashdata('error', 'Invalid account selection.');
            return redirect()->to('/member/loans');
        }

        if ($account['account_status'] !== 'Active') {
            session()->setFlashdata('error', 'This account is not active yet, so it cannot apply for a loan.');
            return redirect()->to('/member/loans');
        }

        // Rate is fixed by account type; never taken from the form.
        $interestRate = CooperativeAccountModel::LOAN_INTEREST_RATE[$account['account_type']] ?? 5.00;

        if ($loanAmount <= 0) {
            session()->setFlashdata('error', 'Loan amount must be greater than zero.');
            return redirect()->to('/member/loans');
        }

        // Check max loan limit (2× total approved contributions)
        $loanService = new LoanService();
        $maxLimit    = $loanService->checkMaxLoanLimit($accountId);

        if ($loanAmount > $maxLimit) {
            session()->setFlashdata('error',
                "Requested amount (PHP {$loanAmount}) exceeds your maximum loan limit (PHP {$maxLimit}).");
            return redirect()->to('/member/loans');
        }

        $interestAmount = round($loanAmount * ($interestRate / 100), 2);
        $now            = date('Y-m-d H:i:s');
        $today          = date('Y-m-d');

        $loanModel = model(LoanModel::class);
        $loanModel->insert([
            'account_id'          => $accountId,
            'total_contributions' => $maxLimit / 2,   // the 1× base
            'maximum_loan_limit'  => $maxLimit,
            'loan_amount'         => $loanAmount,
            'interest_rate'       => $interestRate,
            'interest_amount'     => $interestAmount,
            'loan_term_months'    => $termMonths,
            'application_date'    => $today,
            'loan_status'         => 'Pending',
        ]);

        session()->setFlashdata('success', 'Loan application submitted and pending approval.');
        return redirect()->to('/member/loans');
    }

    // =========================================================================
    // Loan installments
    // =========================================================================

    public function loanInstallments(int $loanId): string|\CodeIgniter\HTTP\RedirectResponse
    {
        $memberId = $this->requireMember();
        $db       = db_connect();

        // Verify loan belongs to member
        $loan = $db->table('loans l')
                   ->select('l.*, ca.account_number')
                   ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                   ->where('l.loan_id', $loanId)
                   ->where('ca.member_id', $memberId)
                   ->get()
                   ->getRowArray();

        if (! $loan) {
            session()->setFlashdata('error', 'Loan not found.');
            return redirect()->to('/member/loans');
        }

        return view('member/loan_installments', [
            'loan'  => $loan,
            'panel' => $this->loanPanel($loanId, $loan['loan_status']),
        ]);
    }

    /** Rendered loan schedule / payment window for one loan. */
    private function loanPanel(int $loanId, string $loanStatus): string
    {
        return view('partials/installment_schedule', [
            'schedule' => (new LoanService())->schedule($loanId),
            'canPay'   => in_array($loanStatus, ['Active', 'Approved'], true),
            'loanId'   => $loanId,
        ]);
    }

    /**
     * Reply to a payment submission: JSON with the refreshed window for the
     * popup, or a redirect with a flash message for a normal form post.
     */
    private function paymentResponse(int $loanId, string $loanStatus, bool $ok, string $message)
    {
        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'ok'      => $ok,
                'message' => $message,
                'html'    => $this->loanPanel($loanId, $loanStatus),
                'csrf'    => csrf_hash(),
            ]);
        }

        session()->setFlashdata($ok ? 'success' : 'error', $message);
        return redirect()->to('/member/loans/' . $loanId . '/installments');
    }

    /**
     * POST: submit a loan payment for pending approval.
     */
    public function submitPayment(int $loanId)
    {
        $memberId = $this->requireMember();

        if (! $this->request->is('post')) {
            return redirect()->to('/member/loans');
        }

        $db = db_connect();

        // Verify ownership
        $loan = $db->table('loans l')
                   ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                   ->where('l.loan_id', $loanId)
                   ->where('ca.member_id', $memberId)
                   ->get()
                   ->getRowArray();

        if (! $loan) {
            session()->setFlashdata('error', 'Loan not found.');
            return redirect()->to('/member/loans');
        }

        if (! in_array($loan['loan_status'] ?? '', ['Active', 'Approved'])) {
            session()->setFlashdata('error', 'This loan is not currently active.');
            return redirect()->to('/member/loans');
        }

        $amounts = [];
        $posted  = (array) $this->request->getPost('amounts');
        foreach ((array) $this->request->getPost('installment_ids') as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $amounts[$id] = round((float) ($posted[$id] ?? 0), 2);
            }
        }

        $now   = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $db->transStart();

        // Lock the loan so two quick submissions cannot both pass the checks below.
        $db->query('SELECT loan_id FROM loans WHERE loan_id = ? FOR UPDATE', [$loanId]);

        $error = (new LoanService())->validatePayment($loanId, $amounts);
        if ($error !== null) {
            $db->transRollback();
            return $this->paymentResponse($loanId, $loan['loan_status'], false, $error);
        }

        $total = round(array_sum($amounts), 2);

        $frId = model(FinancialRecordModel::class)->insert([
            'account_id'      => $loan['account_id'],
            'record_type'     => 'Loan Payment',
            'transaction_date'=> $today,
            'amount'          => $total,
            'approval_status' => 'Pending',
            'processed_by'    => (int) session()->get('user_id'),
            'reference_id'    => $loanId,
        ]);

        $db->table('loan_payments')->insert([
            'loan_id'             => $loanId,
            'payment_date'        => $today,
            'amount_paid'         => $total,
            'financial_record_id' => $frId ?: null,
            'principal_amount'    => $total,
            'interest_amount'     => 0.00,
            'payment_status'      => 'Pending',
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $paymentId = $db->insertID();

        foreach ($amounts as $id => $amount) {
            $db->table('loan_payment_installments')->insert([
                'payment_id'     => $paymentId,
                'installment_id' => $id,
                'amount_applied' => $amount,
            ]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->paymentResponse($loanId, $loan['loan_status'], false, 'Failed to submit payment. Please try again.');
        }

        return $this->paymentResponse($loanId, $loan['loan_status'], true,
            'Payment of PHP ' . number_format($total, 2) . ' submitted and waiting for approval.');
    }

    // =========================================================================
    // Dividends
    // =========================================================================

    public function dividends(): string
    {
        $memberId   = $this->requireMember();
        $db         = db_connect();

        $accounts   = $db->table('cooperative_accounts')
                         ->where('member_id', $memberId)
                         ->get()
                         ->getResultArray();
        $accountIds = array_column($accounts, 'account_id');

        $dividends = [];
        if ($accountIds) {
            $dividends = $db->table('dividends d')
                            ->select('d.*, ca.account_number')
                            ->join('cooperative_accounts ca', 'ca.account_id = d.account_id', 'left')
                            ->whereIn('d.account_id', $accountIds)
                            ->orderBy('d.distribution_year', 'DESC')
                            ->orderBy('d.created_at', 'DESC')
                            ->get()
                            ->getResultArray();
        }

        return view('member/dividends', ['dividends' => $dividends]);
    }

    // =========================================================================
    // Ledger
    // =========================================================================

    /**
     * Member's own financial records in the ledger (read-only view).
     */
    public function ledger(): string
    {
        $memberId   = $this->requireMember();
        $db         = db_connect();

        $accounts   = $db->table('cooperative_accounts')
                         ->where('member_id', $memberId)
                         ->get()
                         ->getResultArray();
        $accountIds = array_column($accounts, 'account_id');

        $ledgerEntries = [];
        if ($accountIds) {
            $ledgerEntries = $db->table('blockchain_ledger bl')
                                ->select('bl.ledger_id, bl.financial_record_id, bl.ledger_sequence, bl.transaction_hash, bl.previous_hash, bl.recorded_at, bl.verification_status, fr.record_type, fr.amount, fr.transaction_date, fr.approval_status, ca.account_number')
                                ->join('financial_records fr', 'fr.financial_record_id = bl.financial_record_id', 'left')
                                ->join('cooperative_accounts ca', 'ca.account_id = fr.account_id', 'left')
                                ->whereIn('fr.account_id', $accountIds)
                                ->orderBy('bl.ledger_sequence', 'DESC')
                                ->get()
                                ->getResultArray();
        }

        return view('member/ledger', ['ledger_entries' => $ledgerEntries]);
    }

    // =========================================================================
    // Profile
    // =========================================================================

    public function profile(): string
    {
        $memberId = $this->requireMember();
        $db       = db_connect();

        $memberModel = model(MemberModel::class);
        $member      = $memberModel->getMemberWithUser($memberId);

        $accounts = $db->table('cooperative_accounts')
                       ->where('member_id', $memberId)
                       ->orderBy('account_id', 'ASC')
                       ->get()
                       ->getResultArray();

        $accountModel = model(CooperativeAccountModel::class);
        foreach ($accounts as &$acc) {
            $acc['standing'] = $accountModel->getStanding($acc);
        }
        unset($acc);

        return view('member/profile', [
            'member'   => $member,
            'accounts' => $accounts,
        ]);
    }

    /**
     * POST: the member opens another account. It becomes active once PHP 300
     * has been contributed to it.
     */
    public function openAccount()
    {
        $memberId = $this->requireMember();

        if (! $this->request->is('post')) {
            return redirect()->to('/member/profile');
        }

        $accountType = $this->request->getPost('account_type');
        if (! in_array($accountType, CooperativeAccountModel::ACCOUNT_TYPES, true)) {
            session()->setFlashdata('error', 'Please choose a valid account type.');
            return redirect()->to('/member/profile');
        }

        $member = model(MemberModel::class)->find($memberId);
        if (($member['membership_status'] ?? '') !== 'Active') {
            session()->setFlashdata('error', 'Your membership is not active.');
            return redirect()->to('/member/profile');
        }

        $accountNumber = model(CooperativeAccountModel::class)->openAccount($memberId, $accountType);

        session()->setFlashdata('success', "Account {$accountNumber} added. It becomes active once you contribute PHP 300 to it.");
        return redirect()->to('/member/profile');
    }

    // =========================================================================
    // Payments
    // =========================================================================

    public function payments(): string
    {
        $memberId = $this->requireMember();
        $db       = db_connect();

        $payments = $db->table('loan_payments lp')
                       ->select('lp.payment_id AS id, lp.loan_id, lp.amount_paid, lp.payment_date, lp.payment_status AS status, l.loan_amount, l.loan_status, ca.account_number, (SELECT COALESCE(SUM(li.amount_paid), 0) FROM loan_installments li WHERE li.loan_id = l.loan_id) AS total_paid, (l.loan_amount + l.interest_amount) AS total_payable', false)
                       ->join('loans l', 'l.loan_id = lp.loan_id')
                       ->join('cooperative_accounts ca', 'ca.account_id = l.account_id')
                       ->where('ca.member_id', $memberId)
                       ->orderBy('lp.created_at', 'DESC')
                       ->get()
                       ->getResultArray();

        return view('member/payments', ['payments' => $payments]);
    }

    // =========================================================================
    // Analytics
    // =========================================================================

    public function analytics(): string
    {
        $this->requireMember();
        return view('admin/analytics', ['layout' => 'layouts/member_main', 'dataUrl' => 'member/analytics/data']);
    }

    public function analyticsData()
    {
        $this->requireMember();

        return $this->response->setJSON((new \App\Services\AnalyticsService())->chartPayload());
    }
}
