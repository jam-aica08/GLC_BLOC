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

        // Recent financial records for activity feed
        $recentRecords = [];
        if ($accountIds) {
            $recentRecords = $db->table('financial_records')
                                ->whereIn('account_id', $accountIds)
                                ->orderBy('created_at', 'DESC')
                                ->limit(5)
                                ->get()
                                ->getResultArray();
        }

        // Total contributions (approved)
        $totalContributions = 0.0;
        if ($accountIds) {
            $row = $db->table('financial_records')
                      ->whereIn('account_id', $accountIds)
                      ->where('record_type', 'Contribution')
                      ->where('approval_status', 'Approved')
                      ->selectSum('amount')
                      ->get()->getRowArray();
            $totalContributions = (float) ($row['amount'] ?? 0);
        }

        // Active loans count
        $activeLoansCount = 0;
        if ($accountIds) {
            $activeLoansCount = $db->table('loans')
                                   ->whereIn('account_id', $accountIds)
                                   ->where('loan_status', 'Active')
                                   ->countAllResults();
        }

        // Total dividends distributed
        $totalDividends = 0.0;
        if ($accountIds) {
            $row = $db->table('dividends')
                      ->whereIn('account_id', $accountIds)
                      ->where('dividend_status', 'Distributed')
                      ->selectSum('dividend_amount')
                      ->get()->getRowArray();
            $totalDividends = (float) ($row['dividend_amount'] ?? 0);
        }

        return view('member/dashboard', [
            'accounts'           => $accounts,
            'recentRecords'      => $recentRecords,
            'totalContributions' => $totalContributions,
            'activeLoansCount'   => $activeLoansCount,
            'totalDividends'     => $totalDividends,
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
                                ->select('c.*, ca.account_number')
                                ->join('cooperative_accounts ca', 'ca.account_id = c.account_id', 'left')
                                ->whereIn('c.account_id', $accountIds)
                                ->orderBy('c.created_at', 'DESC')
                                ->get()
                                ->getResultArray();
        }

        return view('member/contributions', [
            'contributions' => $contributions,
            'accounts'      => $accounts,
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
        $amount    = (float) $this->request->getPost('amount');
        $period    = $this->request->getPost('contribution_period');  // YYYY-MM

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
                        ->select('l.*, ca.account_number')
                        ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                        ->whereIn('l.account_id', $accountIds)
                        ->orderBy('l.application_date', 'DESC')
                        ->get()
                        ->getResultArray();
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

        $accountId   = (int) $this->request->getPost('account_id');
        $loanAmount  = (float) $this->request->getPost('loan_amount');
        $interestRate= (float) $this->request->getPost('interest_rate');
        $termMonths  = (int) $this->request->getPost('loan_term_months');

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

    public function loanInstallments(int $loanId): string
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

        $installments = $db->table('loan_installments')
                           ->where('loan_id', $loanId)
                           ->orderBy('installment_number', 'ASC')
                           ->get()
                           ->getResultArray();

        return view('member/loan_installments', [
            'loan'         => $loan,
            'installments' => $installments,
        ]);
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

        $amountPaid = (float) $this->request->getPost('amount_paid');
        if ($amountPaid <= 0) {
            session()->setFlashdata('error', 'Payment amount must be greater than zero.');
            return redirect()->to('/member/loans/' . $loanId . '/installments');
        }

        $installmentIds = (array) $this->request->getPost('installment_ids');
        $installmentIds = array_map('intval', $installmentIds);
        $installmentIds = array_filter($installmentIds, fn($v) => $v > 0);

        if (empty($installmentIds)) {
            session()->setFlashdata('error', 'Please select at least one installment to pay.');
            return redirect()->to('/member/loans/' . $loanId . '/installments');
        }

        $now   = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        $db->transStart();

        // Create loan_payment record (Pending)
        $db->table('loan_payments')->insert([
            'loan_id'        => $loanId,
            'payment_date'   => $today,
            'amount_paid'    => $amountPaid,
            'principal_amount'=> $amountPaid,
            'interest_amount'=> 0.00,
            'penalty_amount' => 0.00,
            'payment_status' => 'Pending',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $paymentId = $db->insertID();

        // Record installment allocations
        $loanService = new LoanService();
        $allocation  = $loanService->processPayment($loanId, $amountPaid, $installmentIds);

        if (! $allocation['valid']) {
            $db->transRollback();
            session()->setFlashdata('error', $allocation['error']);
            return redirect()->to('/member/loans/' . $loanId . '/installments');
        }

        foreach ($allocation['allocation'] as $alloc) {
            $db->table('loan_payment_installments')->insert([
                'payment_id'     => $paymentId,
                'installment_id' => $alloc['installment_id'],
                'amount_applied' => $alloc['amount_applied'],
                'penalty_amount' => 0.00,
            ]);
        }

        $db->transComplete();

        if (! $db->transStatus()) {
            session()->setFlashdata('error', 'Failed to submit payment. Please try again.');
            return redirect()->to('/member/loans/' . $loanId . '/installments');
        }

        session()->setFlashdata('success', 'Payment submitted and pending approval.');
        return redirect()->to('/member/loans/' . $loanId . '/installments');
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
                                ->select('bl.ledger_id, bl.ledger_sequence, bl.transaction_hash, bl.previous_hash, bl.recorded_at, bl.verification_status, fr.record_type, fr.amount, fr.transaction_date, fr.approval_status, ca.account_number')
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

        $member = $db->table('members m')
                     ->select('m.*, u.username, u.email, u.account_status AS user_status')
                     ->join('users u', 'u.user_id = m.user_id', 'left')
                     ->where('m.member_id', $memberId)
                     ->get()
                     ->getRowArray();

        $accounts = $db->table('cooperative_accounts')
                       ->where('member_id', $memberId)
                       ->get()
                       ->getResultArray();

        return view('member/profile', [
            'member'   => $member,
            'accounts' => $accounts,
        ]);
    }
}
