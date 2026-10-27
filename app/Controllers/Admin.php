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
use App\Services\Notifier;
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
            'pendingRequests'      => (new \App\Services\AccountRequestService())->pendingCount(),
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
        $accountModel->syncStatuses();
        $accountsByMember = [];
        $accounts = $accountModel->withTotals($db->table('cooperative_accounts')
                       ->orderBy('account_id', 'ASC')
                       ->get()
                       ->getResultArray());
        // Why an account cannot be deactivated yet (open loan, waiting payments), shown on its button.
        $blockers = (new \App\Services\AccountStatusService())->blockersFor(array_column($accounts, 'account_id'));
        foreach ($accounts as $acc) {
            $acc['standing'] = $accountModel->getStanding($acc);
            $acc['blockers'] = $blockers[(int) $acc['account_id']] ?? [];
            $accountsByMember[$acc['member_id']][] = $acc;
        }
        foreach ($members as &$member) {
            $member['accounts'] = $accountsByMember[$member['id']] ?? [];
        }
        unset($member);

        return view('admin/members', [
            'members'          => $members,
            'search'           => $search,
            'accountRequests'  => (new \App\Services\AccountRequestService())->pending(),
            'canDeactivateAccounts' => \App\Services\AccountStatusService::available(),
        ]);
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
     * or an error message: PHP 300 to 3,600. The account activates once
     * January up to this month is paid (PHP 3,000 in October), so the rest
     * can follow in later contributions.
     */
    private function openingContribution(): float|string
    {
        $amount = round((float) $this->request->getPost('opening_contribution'), 2);
        $min    = CooperativeAccountModel::CONTRIBUTION_MIN;
        $max    = CooperativeAccountModel::CONTRIBUTION_MAX;

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
     * POST: approve a member's request for a new account; the account is
     * opened as Pending from this month.
     */
    public function approveAccountRequest(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $type = db_connect()->fieldExists('request_type', 'account_requests')
            ? (db_connect()->table('account_requests')->select('request_type')->where('request_id', $id)->get()->getRowArray()['request_type'] ?? 'Open')
            : 'Open';
        [$accountNumber, $error] = (new \App\Services\AccountRequestService())->approve($id, (int) session()->get('user_id'));
        if ($error !== null) {
            session()->setFlashdata('error', $error);
        } else {
            Notifier::resolveForAdmins('review-request-' . $id);
            session()->setFlashdata('success', match ($type) {
                'Deactivate' => "Request approved. Account {$accountNumber} is deactivated; its records are kept.",
                'Reactivate' => "Request approved. Account {$accountNumber} is reactivated.",
                default      => "Request approved. Account {$accountNumber} is open and becomes active once PHP "
                    . number_format(CooperativeAccountModel::joinAmount(), 2) . ' (January to ' . date('F') . ') is paid.',
            });
        }

        return redirect()->to('/admin/members');
    }

    /**
     * POST: reject a member's request for a new account.
     */
    public function rejectAccountRequest(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $rejected = (new \App\Services\AccountRequestService())->reject($id, (int) session()->get('user_id'));
        if ($rejected) {
            Notifier::resolveForAdmins('review-request-' . $id);
        }
        session()->setFlashdata($rejected ? 'success' : 'error', $rejected ? 'Request rejected.' : 'This request is no longer waiting for approval.');

        return redirect()->to('/admin/members');
    }

    /**
     * POST: send an announcement to every active member's notifications.
     */
    public function announce()
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $title   = trim((string) $this->request->getPost('title'));
        $message = trim((string) $this->request->getPost('message'));
        if ($title === '' || $message === '' || mb_strlen($title) > 150 || mb_strlen($message) > 500) {
            session()->setFlashdata('error', 'Enter a title (up to 150 characters) and a message (up to 500 characters).');
            return redirect()->to('/admin/members');
        }

        $count = Notifier::announce($title, $message);
        session()->setFlashdata('success', "Announcement \"{$title}\" sent to {$count} active member(s).");
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

        // The login goes with the membership: signed-in sessions end on their next request.
        $memberModel->update($id, ['membership_status' => 'Inactive']);
        db_connect()->table('users')->where('user_id', (int) $memberModel->find($id)['user_id'])->update(['account_status' => 'Inactive']);
        session()->setFlashdata('success', 'Member deactivated. They can no longer sign in.');
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

        $member = $memberModel->find($id);
        if (! empty($member['login_data_removed_at'])) {
            session()->setFlashdata('error', 'This member\'s sign-in data was removed under the data retention policy. Add them again as a new member instead.');
            return redirect()->to('/admin/members');
        }

        $memberModel->update($id, ['membership_status' => 'Active']);
        db_connect()->table('users')->where('user_id', (int) $member['user_id'])->update(['account_status' => 'Active']);
        // Reactivating restarts the inactivity clock, so the next retention review does not deactivate them again.
        if (\App\Services\RetentionService::available()) {
            db_connect()->table('members')->where('member_id', $id)->update(['retention_flagged_at' => null]);
            \App\Services\RetentionService::touch((int) $member['user_id']);
        }
        session()->setFlashdata('success', 'Member reactivated. They can sign in again.');
        return redirect()->to('/admin/members');
    }

    /**
     * POST: deactivate one account of a member. The member and their other
     * accounts stay as they are; this account's records are kept.
     */
    public function deactivateAccount(int $accountId)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $service = new \App\Services\AccountStatusService();
        $account = $service->find($accountId);
        $error   = $service->deactivate($accountId, (int) session()->get('user_id'), (string) $this->request->getPost('reason'));
        if ($error !== null) {
            session()->setFlashdata('error', $error);
        } else {
            session()->setFlashdata('success', "Account {$account['account_number']} of {$account['first_name']} {$account['last_name']} is deactivated. "
                . 'It takes no new contributions or loans; it stays eligible for dividends and its records are kept.');
        }

        return redirect()->to($this->membersPage());
    }

    /** The Members page with the search the admin was on, so the account stays in view. */
    private function membersPage(): string
    {
        $search = trim((string) $this->request->getPost('q'));

        return '/admin/members' . ($search !== '' ? '?q=' . rawurlencode($search) : '');
    }

    /**
     * POST: reactivate one deactivated account. It is Active again at once if
     * its joining contributions are paid, otherwise Pending until they are.
     */
    public function reactivateAccount(int $accountId)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/members');
        }

        $service = new \App\Services\AccountStatusService();
        $error   = $service->reactivate($accountId);
        if ($error !== null) {
            session()->setFlashdata('error', $error);
        } else {
            $account = $service->find($accountId);
            session()->setFlashdata('success', "Account {$account['account_number']} is reactivated"
                . ($account['account_status'] === 'Active' ? ' and active.' : ' and becomes active once its joining contributions are paid.'));
        }

        return redirect()->to($this->membersPage());
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
                    ->select("c.contribution_id AS id, c.contribution_period, c.amount, c.payment_date, c.payment_status, c.created_at, ca.account_number, ca.account_type, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, fr.approval_status, fr.first_approved_by, fr.second_approved_by, COALESCE(u2.username, u1.username) AS approver_name, COALESCE(fr.second_approval_date, fr.first_approval_date) AS approved_at")
                    ->join('cooperative_accounts ca', 'ca.account_id = c.account_id', 'left')
                    ->join('members m', 'm.member_id = ca.member_id', 'left')
                    ->join('financial_records fr', 'fr.financial_record_id = c.financial_record_id', 'left')
                    ->join('users u1', 'u1.user_id = fr.first_approved_by', 'left')
                    ->join('users u2', 'u2.user_id = fr.second_approved_by', 'left')
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
     * POST: approve a contribution. One administrator's approval is final.
     */
    public function approveContribution(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/contributions');
        }

        $contribution = db_connect()->table('contributions')->where('contribution_id', $id)->get()->getRowArray();
        [$ok, $message] = $contribution
            ? $this->approveRecord((int) $contribution['financial_record_id'])
            : [false, 'Contribution not found.'];

        session()->setFlashdata($ok ? 'success' : 'error', $message);
        return redirect()->to('/admin/contributions');
    }

    /**
     * POST: reject a contribution that is still waiting for approval.
     */
    public function rejectContribution(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/contributions');
        }

        $contribution = db_connect()->table('contributions')->where('contribution_id', $id)->get()->getRowArray();
        [$ok, $message] = $contribution
            ? $this->rejectRecord((int) $contribution['financial_record_id'])
            : [false, 'Contribution not found.'];

        session()->setFlashdata($ok ? 'success' : 'error', $message);
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

        // A missed payment: a month past its due date and still open, or an open
        // month still carrying unpaid money moved in from missed months.
        $query->select("EXISTS (SELECT 1 FROM loan_installments lo WHERE lo.loan_id = l.loan_id AND lo.payment_status NOT IN ('Paid', 'Partial')
                        AND (lo.due_date < CURDATE() OR lo.carried_over > lo.amount_paid)) AS has_missed", false);

        [$loans, $page] = $this->paginate($query);

        // What a contribution offset could settle, for loans with a missed payment.
        if (\App\Services\WithdrawalService::available()) {
            $offsets = new \App\Services\LoanOffsetService();
            foreach ($loans as &$loan) {
                if ($loan['has_missed'] && in_array($loan['status'], ['Active', 'Defaulted'], true)) {
                    $loan['offset'] = $offsets->preview((int) $loan['id']);
                }
            }
            unset($loan);
        }

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

        // Contributions can be rejected, or the account closed, after someone
        // applies, so the account and the limit (twice the approved
        // contributions) are checked again before any money is released.
        model(CooperativeAccountModel::class)->syncStatuses();
        $account = $db->table('cooperative_accounts')->where('account_id', $loan['account_id'])->get()->getRowArray();
        if (($account['account_status'] ?? '') !== 'Active') {
            session()->setFlashdata('error', "Account {$account['account_number']} is no longer active, so loan #{$id} cannot be approved. Reject it instead.");
            return redirect()->to('/admin/loans');
        }
        $limit = $loanService->checkMaxLoanLimit((int) $loan['account_id']);
        if ((float) $loan['loan_amount'] > $limit + 0.004) {
            session()->setFlashdata('error', "Loan #{$id} (PHP " . number_format((float) $loan['loan_amount'], 2) . ') is now above this member\'s limit of PHP '
                . number_format($limit, 2) . ' (twice their approved contributions). Reject it, or approve it once more contributions are approved.');
            return redirect()->to('/admin/loans');
        }

        // The form does not send a release date; a posted one must be a real date (it sets every due date).
        $releaseDate = (string) $this->request->getPost('release_date');
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $releaseDate, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $releaseDate = $today;
        }

        $db->transStart();

        // Only a loan that is still Pending can be approved, so a second click
        // cannot create the installments twice.
        $db->table('loans')->where('loan_id', $id)->where('loan_status', 'Pending')->update([
            'loan_status'   => 'Active',
            'approval_date' => $today,
            'release_date'  => $releaseDate,
            'approved_by'   => $currentUserId,
            'updated_at'    => $now,
        ]);
        if ($db->affectedRows() < 1) {
            $db->transRollback();
            session()->setFlashdata('error', 'This loan was just approved or rejected by another administrator.');
            return redirect()->to('/admin/loans');
        }

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

        Notifier::toAccountOwner((int) $loan['account_id'], 'loan', 'Loan approved',
            'Your loan #' . $id . ' of PHP ' . number_format((float) $loan['loan_amount'], 2) . ' was approved. Your monthly schedule is ready.',
            'member/loans', 'loan-approved-' . $id);
        Notifier::resolveForAdmins('review-loan-' . $id);

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

        db_connect()->table('loans')->where('loan_id', $id)->where('loan_status', 'Pending')->update(['loan_status' => 'Rejected', 'updated_at' => date('Y-m-d H:i:s')]);
        if (db_connect()->affectedRows() < 1) {
            session()->setFlashdata('error', 'This loan was just approved or rejected by another administrator.');
            return redirect()->to('/admin/loans');
        }

        Notifier::toAccountOwner((int) $loan['account_id'], 'loan', 'Loan application rejected',
            'Your loan application #' . $id . ' of PHP ' . number_format((float) $loan['loan_amount'], 2) . ' was rejected.',
            'member/loans', 'loan-rejected-' . $id);
        Notifier::resolveForAdmins('review-loan-' . $id);

        session()->setFlashdata('success', 'Loan rejected.');
        return redirect()->to('/admin/loans');
    }

    /**
     * POST: settle an unpaid loan from the member's contributions (Loan Offset
     * Policy). Only for a loan with a missed payment; see LoanOffsetService.
     */
    public function offsetLoan(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/loans');
        }
        if (! \App\Services\WithdrawalService::available()) {
            session()->setFlashdata('error', 'Loan offsets need a database update. Open Settings and apply database updates.');
            return redirect()->to('/admin/loans');
        }
        if (trim((string) $this->request->getPost('confirm')) !== 'OFFSET') {
            session()->setFlashdata('error', 'Type OFFSET to confirm the loan offset.');
            return redirect()->to('/admin/loans');
        }

        $raw    = trim((string) $this->request->getPost('amount'));
        $amount = $raw === '' ? null : round((float) $raw, 2);
        $error  = (new \App\Services\LoanOffsetService())->apply($id, $amount, (int) session()->get('user_id'));
        if ($error !== null) {
            session()->setFlashdata('error', "Loan #{$id}: {$error}");
        } else {
            session()->setFlashdata('success', "Offset applied to loan #{$id} from the member's contributions. It is in the ledger and the member was notified.");
        }

        return redirect()->to('/admin/loans');
    }

    // =========================================================================
    // Withdrawals
    // =========================================================================

    public function withdrawals()
    {
        $this->requireAdmin();

        if (! \App\Services\WithdrawalService::available()) {
            session()->setFlashdata('error', 'Withdrawals need a database update. Open Settings and apply database updates.');
            return redirect()->to('/admin/settings');
        }

        $status = (string) $this->request->getGet('status');
        $search = trim((string) $this->request->getGet('q'));
        [$rows, $page] = $this->paginate((new \App\Services\WithdrawalService())->query($status, $search));

        // Show at a glance which waiting requests are blocked by an outstanding loan.
        foreach ($rows as &$row) {
            $row['loans'] = $row['status'] === 'Pending' ? \App\Services\ContributionBalance::outstandingLoans((int) $row['member_id']) : [];
        }
        unset($row);

        return view('admin/withdrawals', ['withdrawals' => $rows, 'page' => $page, 'filterStatus' => $status, 'search' => $search]);
    }

    /** POST: approve a waiting withdrawal (checked again against the policy). */
    public function approveWithdrawal(int $id)
    {
        $this->requireAdmin();

        $w = db_connect()->table('withdrawals')->where('withdrawal_id', $id)->get()->getRowArray();
        if (! $this->request->is('post') || ! $w) {
            return redirect()->to('/admin/withdrawals');
        }
        [$ok, $message] = $this->approveRecord((int) $w['financial_record_id']);
        session()->setFlashdata($ok ? 'success' : 'error', $ok ? 'Withdrawal approved and recorded in the blockchain ledger. Release PHP '
            . number_format((float) $w['amount'], 2) . " to the member by {$w['method']}." : $message);

        return redirect()->to('/admin/withdrawals');
    }

    /** POST: reject a waiting withdrawal. */
    public function rejectWithdrawal(int $id)
    {
        $this->requireAdmin();

        $w = db_connect()->table('withdrawals')->where('withdrawal_id', $id)->get()->getRowArray();
        if (! $this->request->is('post') || ! $w) {
            return redirect()->to('/admin/withdrawals');
        }
        [$ok, $message] = $this->rejectRecord((int) $w['financial_record_id']);
        session()->setFlashdata($ok ? 'success' : 'error', $message);

        return redirect()->to('/admin/withdrawals');
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
                    ->select("lp.payment_id AS id, lp.loan_id, lp.amount_paid, lp.payment_date, lp.payment_status AS status, lp.financial_record_id, l.loan_amount, l.loan_status, m.member_number, ca.account_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, (SELECT COALESCE(SUM(li.amount_paid), 0) FROM loan_installments li WHERE li.loan_id = l.loan_id) AS total_paid, (l.loan_amount + l.interest_amount) AS total_payable, fr.first_approved_by, COALESCE(u2.username, u1.username) AS approver_name, COALESCE(fr.second_approval_date, fr.first_approval_date) AS approved_at, (SELECT GROUP_CONCAT(CONCAT('Month ', li2.installment_number, ': ₱', FORMAT(lpi.amount_applied, 2)) ORDER BY li2.installment_number SEPARATOR ', ') FROM loan_payment_installments lpi JOIN loan_installments li2 ON li2.installment_id = lpi.installment_id WHERE lpi.payment_id = lp.payment_id) AS months", false)
                    ->join('financial_records fr', 'fr.financial_record_id = lp.financial_record_id', 'left')
                    ->join('users u1', 'u1.user_id = fr.first_approved_by', 'left')
                    ->join('users u2', 'u2.user_id = fr.second_approved_by', 'left')
                    ->join('loans l', 'l.loan_id = lp.loan_id', 'left')
                    ->join('cooperative_accounts ca', 'ca.account_id = l.account_id', 'left')
                    ->join('members m', 'm.member_id = ca.member_id', 'left')
                    ->orderBy('lp.created_at', 'DESC');

        if ($filterStatus !== '') {
            $query->where('lp.payment_status', $filterStatus);
        }

        $search = trim((string) $this->request->getGet('q'));
        $this->searchMemberColumns($query, $search, 'lp.loan_id');
        $query->orderBy('lp.payment_id', 'DESC');

        [$payments, $page] = $this->paginate($query);

        return view('admin/payments', [
            'payments'     => $payments,
            'filterStatus' => $filterStatus,
            'search'       => $search,
            'page'         => $page,
        ]);
    }

    /**
     * POST: approve a loan payment. One administrator's approval is final:
     * the payment is applied to its installments and added to the ledger.
     */
    public function approvePayment(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/payments');
        }

        $db      = db_connect();
        $payment = $db->table('loan_payments')->where('payment_id', $id)->get()->getRowArray();
        if (! $payment || $payment['payment_status'] !== 'Pending') {
            session()->setFlashdata('error', 'This payment is no longer waiting for approval.');
            return redirect()->to('/admin/payments');
        }

        $frId = (int) $payment['financial_record_id'];
        if (! $frId || ! model(FinancialRecordModel::class)->find($frId)) {
            // Older payments may lack a financial record; create it so the approval is recorded.
            $loan = $db->table('loans')->where('loan_id', $payment['loan_id'])->get()->getRowArray();
            $frId = (int) model(FinancialRecordModel::class)->insert([
                'account_id'       => $loan['account_id'],
                'record_type'      => 'Loan Payment',
                'transaction_date' => $payment['payment_date'],
                'amount'           => $payment['amount_paid'],
                'approval_status'  => 'Pending',
                'processed_by'     => null,
                'reference_id'     => $payment['loan_id'],
            ]);
            $db->table('loan_payments')->where('payment_id', $id)->update(['financial_record_id' => $frId]);
        }

        [$ok, $message] = $this->approveRecord($frId);
        session()->setFlashdata($ok ? 'success' : 'error', $message);
        return redirect()->to('/admin/payments');
    }

    /**
     * Approve a Pending financial record. One administrator is enough; the
     * update only succeeds while the record is still Pending, so a second
     * click or a second administrator cannot approve or apply it twice.
     *
     * @return array{0: bool, 1: string} [ok, message]
     */
    private function approveRecord(int $frId): array
    {
        $db      = db_connect();
        $frModel = model(FinancialRecordModel::class);
        $adminId = (int) session()->get('user_id');
        $now     = date('Y-m-d H:i:s');

        $fr = $frId ? $frModel->find($frId) : null;
        if (! $fr || $fr['approval_status'] !== 'Pending') {
            return [false, 'This record is no longer waiting for approval.'];
        }
        if ($fr['record_type'] === 'Dividend') {
            return [false, 'Dividends are approved as a whole distribution on the Dividends page.'];
        }
        if ($fr['record_type'] === 'Loan Payment') {
            $payment = $db->table('loan_payments')->where('financial_record_id', $frId)->get()->getRowArray();
            if ($payment && ($problem = $this->paymentOverBalance($payment))) {
                return [false, $problem];
            }
        }
        // A withdrawal is checked again: no outstanding loan, and the money is still there.
        if ($fr['record_type'] === 'Withdrawal' && ($problem = (new \App\Services\WithdrawalService())->checkBeforeApproval($frId))) {
            return [false, $problem];
        }

        $account = $db->table('cooperative_accounts')->where('account_id', $fr['account_id'])->get()->getRowArray();

        $db->transBegin();
        try {
            // A record half-approved under the old two-step rule keeps its first approver.
            $tier = empty($fr['first_approved_by']) ? 'first' : 'second';
            $db->table('financial_records')
               ->where('financial_record_id', $frId)
               ->where('approval_status', 'Pending')
               ->update([
                   "{$tier}_approved_by"   => $adminId,
                   "{$tier}_approval_date" => $now,
                   'approval_status'       => 'Approved',
                   'updated_at'            => $now,
               ]);
            if ($db->affectedRows() < 1) {
                throw new \DomainException('This record was just approved or rejected by another administrator. Reload the page.');
            }

            $this->applyApprovedRecord($fr);

            if ($db->transStatus() === false) {
                throw new \RuntimeException('The database refused the approval.');
            }
            $db->transCommit();
        } catch (\DomainException $e) {
            $db->transRollback();
            return [false, $e->getMessage()];
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'approveRecord #' . $frId . ' failed: ' . $e->getMessage());
            return [false, 'Could not approve the record. Nothing was changed.'];
        }

        // Ledger entry, once per record.
        if (! $db->table('blockchain_ledger')->where('financial_record_id', $frId)->countAllResults()) {
            try {
                (new LedgerService())->createEntry($frId, $frModel->find($frId));
            } catch (\Throwable $e) {
                log_message('error', 'LedgerService::createEntry failed for FR #' . $frId . ': ' . $e->getMessage());
            }
        }

        $this->notifyRecord($fr, 'approved', $account);
        Notifier::resolveForAdmins('review-record-' . $frId);

        $label = ['Contribution' => 'Contribution', 'Loan Payment' => 'Payment', 'Withdrawal' => 'Withdrawal'][$fr['record_type']] ?? 'Financial record';
        return [true, "{$label} approved and recorded in the blockchain ledger."];
    }

    /**
     * Reject a Pending financial record and its contribution or loan payment.
     *
     * @return array{0: bool, 1: string} [ok, message]
     */
    private function rejectRecord(int $frId): array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $fr  = $frId ? model(FinancialRecordModel::class)->find($frId) : null;

        if (! $fr || $fr['approval_status'] !== 'Pending') {
            return [false, 'This record is no longer waiting for approval.'];
        }
        if ($fr['record_type'] === 'Dividend') {
            return [false, 'Dividends are rejected as a whole distribution on the Dividends page.'];
        }

        $db->transStart();
        $db->table('financial_records')
           ->where('financial_record_id', $frId)->where('approval_status', 'Pending')
           ->update(['approval_status' => 'Rejected', 'updated_at' => $now]);
        $changed = $db->affectedRows() > 0;
        $source  = ['Contribution' => ['contributions', 'payment_status'], 'Loan Payment' => ['loan_payments', 'payment_status'],
                    'Withdrawal' => ['withdrawals', 'status']][$fr['record_type']] ?? null;
        if ($changed && $source) {
            [$table, $column] = $source;
            $db->table($table)
               ->where('financial_record_id', $frId)->where($column, 'Pending')
               ->update([$column => 'Rejected', 'updated_at' => $now]);
        }
        $db->transComplete();

        if (! $changed || ! $db->transStatus()) {
            return [false, 'This record is no longer waiting for approval.'];
        }

        $this->notifyRecord($fr, 'rejected');
        Notifier::resolveForAdmins('review-record-' . $frId);

        $label = ['Contribution' => 'Contribution', 'Loan Payment' => 'Payment', 'Withdrawal' => 'Withdrawal'][$fr['record_type']] ?? 'Financial record';
        return [true, "{$label} rejected."];
    }

    /** Tell the member about an approved or rejected contribution or loan payment. */
    private function notifyRecord(array $fr, string $decision, ?array $accountBefore = null): void
    {
        $db     = db_connect();
        $frId   = (int) $fr['financial_record_id'];
        $amount = 'PHP ' . number_format((float) $fr['amount'], 2);
        $title  = ucfirst($decision);

        if ($fr['record_type'] === 'Contribution') {
            $c = $db->table('contributions c')->select('c.contribution_id, c.contribution_period, ca.account_number, ca.account_id, ca.account_status')
                    ->join('cooperative_accounts ca', 'ca.account_id = c.account_id')
                    ->where('c.financial_record_id', $frId)->get()->getRowArray();
            if (! $c) {
                return;
            }
            Notifier::toAccountOwner((int) $c['account_id'], 'contribution', "Contribution {$decision}",
                "Your contribution of {$amount} for {$c['contribution_period']} on account {$c['account_number']} was {$decision}.",
                'member/contributions', "contribution-{$decision}-{$c['contribution_id']}");
            if ($decision === 'approved' && ($accountBefore['account_status'] ?? '') === 'Pending' && $c['account_status'] === 'Active') {
                Notifier::toAccountOwner((int) $c['account_id'], 'account', 'Account activated',
                    "Account {$c['account_number']} is now active.", 'member/profile', "account-active-{$c['account_id']}");
            }
        } elseif ($fr['record_type'] === 'Loan Payment') {
            $p = $db->table('loan_payments')->where('financial_record_id', $frId)->get()->getRowArray();
            if ($p) {
                Notifier::toAccountOwner((int) $fr['account_id'], 'payment', "Loan payment {$decision}",
                    "Your payment of {$amount} for loan #{$p['loan_id']} was {$decision}.",
                    'member/loans', "payment-{$decision}-{$p['payment_id']}");
            }
        } elseif ($fr['record_type'] === 'Withdrawal') {
            $w = $db->table('withdrawals w')->select('w.withdrawal_id, w.method, ca.account_number')
                    ->join('cooperative_accounts ca', 'ca.account_id = w.account_id')
                    ->where('w.financial_record_id', $frId)->get()->getRowArray();
            if ($w) {
                Notifier::toAccountOwner((int) $fr['account_id'], 'withdrawal', "Withdrawal {$decision}",
                    "Your withdrawal of {$amount} from account {$w['account_number']} was {$decision}"
                    . ($decision === 'approved' ? " ({$w['method']})." : '.'),
                    'member/withdrawals', "withdrawal-{$decision}-{$w['withdrawal_id']}");
            }
        }
    }

    /**
     * Once a financial record is approved, bring its source row in line:
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
            model(CooperativeAccountModel::class)->syncStatuses();
        } elseif ($fr['record_type'] === 'Loan Payment') {
            $payment = $db->table('loan_payments')->where('financial_record_id', $frId)->get()->getRowArray();
            if ($payment && $payment['payment_status'] === 'Pending') {
                $this->applyLoanPayment($payment);
            }
        } elseif ($fr['record_type'] === 'Withdrawal') {
            $db->table('withdrawals')
               ->where('financial_record_id', $frId)->where('status', 'Pending')
               ->update(['status' => 'Approved', 'updated_at' => date('Y-m-d H:i:s')]);
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
     * POST: reject a loan payment that is still waiting for approval.
     */
    public function rejectPayment(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/payments');
        }

        $db      = db_connect();
        $payment = $db->table('loan_payments')->where('payment_id', $id)->get()->getRowArray();
        if (! $payment || $payment['payment_status'] !== 'Pending') {
            session()->setFlashdata('error', 'This payment is no longer waiting for approval.');
            return redirect()->to('/admin/payments');
        }

        if ($payment['financial_record_id']) {
            [$ok, $message] = $this->rejectRecord((int) $payment['financial_record_id']);
        } else {
            $db->table('loan_payments')->where('payment_id', $id)->where('payment_status', 'Pending')
               ->update(['payment_status' => 'Rejected', 'updated_at' => date('Y-m-d H:i:s')]);
            [$ok, $message] = [$db->affectedRows() > 0, 'Payment rejected.'];
        }

        session()->setFlashdata($ok ? 'success' : 'error', $message);
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
                      ->select("fr.financial_record_id AS id, fr.record_type AS transaction_type, fr.amount, fr.transaction_date, fr.approval_status, fr.created_at, fr.first_approved_by AS approver1_id, fr.first_approval_date AS approved_at_1, fr.second_approved_by, fr.second_approval_date AS approved_at_2, ca.account_number, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, COALESCE(u2.username, u1.username) AS approver_name, COALESCE(fr.second_approval_date, fr.first_approval_date) AS approved_at")
                      ->join('cooperative_accounts ca', 'ca.account_id = fr.account_id', 'left')
                      ->join('members m', 'm.member_id = ca.member_id', 'left')
                      ->join('users u1', 'u1.user_id = fr.first_approved_by', 'left')
                      ->join('users u2', 'u2.user_id = fr.second_approved_by', 'left')
                      ->orderBy('fr.created_at', 'DESC')
                      ->orderBy('fr.financial_record_id', 'DESC');

        // Paged like the other lists: this table grows with every transaction.
        $search = trim((string) $this->request->getGet('q'));
        $this->searchMemberColumns($records, $search, 'fr.financial_record_id');
        [$records, $page] = $this->paginate($records);

        return view('admin/financial_records', ['financialRecords' => $records, 'page' => $page, 'search' => $search]);
    }

    /**
     * POST: approve a financial record (one administrator is enough).
     */
    public function approveFinancialRecord(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/financial-records');
        }

        [$ok, $message] = $this->approveRecord($id);
        session()->setFlashdata($ok ? 'success' : 'error', $message);
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

        [$ok, $message] = $this->rejectRecord($id);
        session()->setFlashdata($ok ? 'success' : 'error', $message);
        return redirect()->to('/admin/financial-records');
    }

    // =========================================================================
    // Dividends
    // =========================================================================

    public function dividends(): string
    {
        $this->requireAdmin();

        $service = new \App\Services\DividendService();
        $year    = (int) ($this->request->getGet('year') ?: date('Y'));

        return view('admin/dividends', [
            'year'          => $year,
            'plan'          => $service->plan($year),
            'available'     => $service->available(),
            'distributions' => $service->distributions(),
            'dividends'     => $service->records($year),
        ]);
    }

    /**
     * POST: create the year's distribution as a Pending batch after the
     * administrator confirmed the figures. An administrator then approves it.
     */
    public function distributeDividends()
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/dividends');
        }

        $year = (int) $this->request->getPost('distribution_year');
        [$id, $error] = (new \App\Services\DividendService())->create(
            $year,
            (string) $this->request->getPost('distribution_date'),
            (int) session()->get('user_id'),
            (float) $this->request->getPost('expected_pool'),
            (int) $this->request->getPost('expected_accounts'),
        );

        if (! $error) {
            Notifier::toAdmins('dividend', 'Dividend distribution to review',
                session()->get('username') . " created distribution #{$id} for {$year}. It needs an administrator's approval.",
                'admin/dividends?year=' . $year, 'review-dividend-' . $id, (int) session()->get('user_id'));
        }
        session()->setFlashdata($error ? 'error' : 'success', $error
            ?? "Distribution #{$id} for {$year} was created and is waiting for approval.");

        return redirect()->to('/admin/dividends?year=' . $year);
    }

    /** POST: approve a distribution batch (one administrator is enough). */
    public function approveDividendDistribution(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/dividends');
        }

        [$message, $ok] = (new \App\Services\DividendService())->approve($id, (int) session()->get('user_id'));
        if ($ok) {
            Notifier::resolveForAdmins('review-dividend-' . $id);
        }
        session()->setFlashdata($ok ? 'success' : 'error', $message);

        return redirect()->to('/admin/dividends?year=' . (int) $this->request->getPost('year'));
    }

    /** POST: reject a Pending distribution batch. */
    public function rejectDividendDistribution(int $id)
    {
        $this->requireAdmin();

        if (! $this->request->is('post')) {
            return redirect()->to('/admin/dividends');
        }

        $ok = (new \App\Services\DividendService())->reject($id, (int) session()->get('user_id'));
        if ($ok) {
            Notifier::resolveForAdmins('review-dividend-' . $id);
        }
        session()->setFlashdata($ok ? 'success' : 'error', $ok
            ? 'Distribution rejected. Its records are kept as Rejected and the year can be distributed again.'
            : 'This distribution is no longer waiting for approval.');

        return redirect()->to('/admin/dividends?year=' . (int) $this->request->getPost('year'));
    }

    // =========================================================================
    // Notifications (each administrator has their own)
    // =========================================================================

    /** Where to go after marking notifications read: the admin page they were on. */
    private function backTo(): string
    {
        $next = trim((string) $this->request->getPost('next'), '/');

        return preg_match('#^admin(/[A-Za-z0-9_/-]*)?$#', $next) ? '/' . $next : '/admin/dashboard';
    }

    /** POST: mark one of the administrator's own notifications as read. */
    public function readNotification(int $id)
    {
        $this->requireAdmin();
        if ($this->request->is('post')) {
            Notifier::adminMarkRead((int) session()->get('user_id'), $id);
        }

        return redirect()->to($this->backTo());
    }

    /** POST: mark all of the administrator's notifications as read. */
    public function readAllNotifications()
    {
        $this->requireAdmin();
        if ($this->request->is('post')) {
            Notifier::adminMarkRead((int) session()->get('user_id'));
        }

        return redirect()->to($this->backTo());
    }

    /**
     * POST (from the bell): the administrator opened their notifications, so
     * everything but items still to review counts as read. JSON: the unread
     * count left for the badge and a fresh CSRF token for the page.
     */
    public function seenNotifications()
    {
        $this->requireAdmin();
        $userId = (int) session()->get('user_id');
        Notifier::adminMarkSeen($userId);

        return $this->response->setJSON(['unread' => Notifier::adminUnreadCount($userId), 'csrf' => csrf_hash()]);
    }

    /** GET: every notification of the signed-in administrator. */
    public function notifications(): string
    {
        $this->requireAdmin();
        $userId = (int) session()->get('user_id');

        return view('admin/notifications', [
            'notifications' => Notifier::adminLatest($userId, 200),
            'unread'        => Notifier::adminUnreadCount($userId),
        ]);
    }

    // =========================================================================
    // Audit Logs (super admin only)
    // =========================================================================

    /** The signed-in administrator is the super admin (checked in the database, not just the session). */
    private function isSuperAdmin(): bool
    {
        return \App\Libraries\AccountAccess::isSuperAdmin((int) session()->get('user_id'));
    }

    public function auditLogs()
    {
        $this->requireAdmin();

        if (! $this->isSuperAdmin()) {
            session()->setFlashdata('error', 'Only the super administrator can view the audit logs.');
            return redirect()->to('/admin/dashboard');
        }
        if (! \App\Libraries\AuditLog::available()) {
            session()->setFlashdata('error', 'Audit logs need a database update. Open Settings and apply database updates.');
            return redirect()->to('/admin/settings');
        }

        $filters = [
            'user'   => trim((string) $this->request->getGet('user')),
            'action' => trim((string) $this->request->getGet('action')),
            'result' => (string) $this->request->getGet('result'),
            'from'   => (string) $this->request->getGet('from'),
            'to'     => (string) $this->request->getGet('to'),
        ];

        $query = db_connect()->table('audit_logs')->orderBy('created_at', 'DESC')->orderBy('audit_id', 'DESC');
        if ($filters['user'] !== '') {
            $query->like('username', $filters['user']);
        }
        if ($filters['action'] !== '') {
            $query->like('action', $filters['action']);
        }
        if (in_array($filters['result'], ['Success', 'Failed'], true)) {
            $query->where('result', $filters['result']);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
            $query->where('created_at >=', $filters['from'] . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
            $query->where('created_at <=', $filters['to'] . ' 23:59:59');
        }

        [$logs, $page] = $this->paginate($query);

        return view('admin/audit_logs', ['logs' => $logs, 'page' => $page, 'filters' => $filters]);
    }

    // =========================================================================
    // Blockchain Ledger
    // =========================================================================

    public function ledger(): string
    {
        $this->requireAdmin();

        $db = db_connect();

        // Totals for the whole chain; the table itself is paged, newest block first.
        $counts = ['Valid' => 0, 'Tampered' => 0];
        foreach ($db->table('blockchain_ledger')->select('verification_status, COUNT(*) AS n')->groupBy('verification_status')->get()->getResultArray() as $row) {
            $counts[$row['verification_status']] = (int) $row['n'];
        }

        $query = $db->table('blockchain_ledger bl')
                    ->select('bl.ledger_id, bl.financial_record_id, bl.ledger_sequence AS sequence, bl.transaction_hash AS hash, bl.previous_hash, bl.recorded_at, bl.verification_status AS status, fr.record_type, fr.amount, fr.approval_status')
                    ->join('financial_records fr', 'fr.financial_record_id = bl.financial_record_id', 'left')
                    ->orderBy('bl.ledger_sequence', 'DESC');

        // Search by block / record number, hash prefix or record type.
        $search = trim((string) $this->request->getGet('q'));
        if ($search !== '') {
            $query->groupStart();
            if (ctype_digit(ltrim($search, '#'))) {
                $n = (int) ltrim($search, '#');
                $query->where('bl.ledger_id', $n)->orWhere('bl.ledger_sequence', $n)->orWhere('bl.financial_record_id', $n);
            } else {
                $query->like('bl.transaction_hash', $search, 'after')->orLike('fr.record_type', $search);
            }
            $query->groupEnd();
        }
        [$ledgerEntries, $page] = $this->paginate($query);

        return view('admin/ledger', ['ledgerEntries' => $ledgerEntries, 'page' => $page, 'search' => $search, 'counts' => $counts]);
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
        if ($result['result'] !== 'PASS') {
            Notifier::toAdmins('ledger', 'Ledger integrity problem',
                "Verification found {$result['tampered']} tampered of {$result['checked']} ledger entries"
                . ($result['missing'] ? " and {$result['missing']} approved record(s) missing from the ledger" : '') . '. Check the ledger now.',
                'admin/ledger', 'ledger-tampered-' . date('Y-m-d'));
        }

        // If the caller accepts JSON, return JSON; otherwise flash and redirect
        if (strpos($this->request->getHeaderLine('Accept'), 'application/json') !== false) {
            return $this->response->setJSON($result);
        }

        $message = "Ledger verification {$result['result']}: "
                 . "{$result['valid']} valid, {$result['tampered']} tampered "
                 . "out of {$result['checked']} checked"
                 . ($result['missing']
                     ? "; {$result['missing']} approved record(s) are not in the ledger at all (record #"
                       . implode(', #', $result['missing_ids']) . ($result['missing'] > count($result['missing_ids']) ? ', …' : '')
                       . '): they were added outside the app, or their ledger entry failed to save.'
                     : '.');

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
        return view('admin/analytics', ['activityUrl' => 'admin/analytics/activity']);
    }

    // =========================================================================
    // Reports
    // =========================================================================

    /** Report filters from the query string, with safe defaults. */
    private function reportFilters(): array
    {
        $get    = fn($k) => trim((string) $this->request->getGet($k));
        $preset = $get('period') ?: 'year';
        $date   = fn($v) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;

        [$from, $to] = match ($preset) {
            'month'  => [date('Y-m-01'), date('Y-m-d')],
            '30days' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
            'all'    => ['2000-01-01', date('Y-m-d')],
            'custom' => [$date($get('from')) ?? date('Y-01-01'), $date($get('to')) ?? date('Y-m-d')],
            default  => [date('Y-01-01'), date('Y-m-d')],
        };
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $types    = ['Contribution', 'Loan Disbursement', 'Loan Payment', 'Dividend', 'Withdrawal', 'Loan Offset'];
        $category = in_array($get('category'), ['summary', 'transactions', 'membership', 'ledger'], true) ? $get('category') : 'summary';

        return [
            'period'   => in_array($preset, ['month', 'year', '30days', 'all', 'custom'], true) ? $preset : 'year',
            'from'     => $from,
            'to'       => $to,
            'category' => $category,
            'type'     => in_array($get('type'), $types, true) ? $get('type') : '',
            'status'   => in_array($get('status'), ['Pending', 'Approved', 'Rejected'], true) ? $get('status') : '',
            'types'    => $types,
        ];
    }

    /** Financial records matching the report filters. */
    private function reportRecords(array $f, bool $withColumns = true): \CodeIgniter\Database\BaseBuilder
    {
        $q = db_connect()->table('financial_records fr');
        if ($withColumns) {
            $q->select("fr.financial_record_id, fr.record_type, fr.transaction_date, fr.amount, fr.approval_status, ca.account_number, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name", false);
        }
        $q->join('cooperative_accounts ca', 'ca.account_id = fr.account_id', 'left')
            ->join('members m', 'm.member_id = ca.member_id', 'left')
            ->where('fr.transaction_date >=', $f['from'])
            ->where('fr.transaction_date <=', $f['to']);
        if ($f['type'] !== '') {
            $q->where('fr.record_type', $f['type']);
        }
        if ($f['status'] !== '') {
            $q->where('fr.approval_status', $f['status']);
        }

        return $q;
    }

    public function reports(): string
    {
        $this->requireAdmin();

        $db = db_connect();
        $f  = $this->reportFilters();

        // Totals by type and status for the period (same filters as the list).
        $data = ['f' => $f, 'summary' => $this->reportSummary($f), 'previous' => null];

        if ($f['category'] === 'summary') {
            // Period to compare with on each tile: the same dates last year or last
            // month for those presets, otherwise the same number of days just before.
            if ($f['period'] !== 'all') {
                if ($f['period'] === 'year' || $f['period'] === 'month') {
                    $prevFrom = self::sameDayBack($f['from'], $f['period']);
                    $prevTo   = self::sameDayBack($f['to'], $f['period']);
                } else {
                    $days     = (int) ((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
                    $prevTo   = date('Y-m-d', strtotime($f['from'] . ' -1 day'));
                    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
                }
                $data['previous'] = ['from' => $prevFrom, 'to' => $prevTo, 'summary' => $this->reportSummary(['from' => $prevFrom, 'to' => $prevTo] + $f)];
            }
            $data['trend'] = $this->reportTrend($f);
        }
        if ($f['category'] === 'transactions') {
            $data['records'] = $this->reportRecords($f)->orderBy('fr.transaction_date', 'DESC')->orderBy('fr.financial_record_id', 'DESC')
                ->limit(500)->get()->getResultArray();
        }
        if ($f['category'] === 'membership') {
            $data['membership'] = [
                'members'        => $db->table('members')->countAllResults(),
                'active_members' => $db->table('members')->where('membership_status', 'Active')->countAllResults(),
                'new_members'    => $db->table('members')->where('membership_date >=', $f['from'])->where('membership_date <=', $f['to'])->countAllResults(),
                'accounts'       => $db->table('cooperative_accounts')->select('account_type, account_status, COUNT(*) AS n')
                                       ->groupBy('account_type, account_status')->get()->getResultArray(),
                'opened'         => $db->table('cooperative_accounts')->where('account_start_date >=', $f['from'])->where('account_start_date <=', $f['to'])->countAllResults(),
            ];
        }
        if ($f['category'] === 'ledger') {
            $data['ledger'] = [
                'entries'  => $db->table('blockchain_ledger')->where('recorded_at >=', $f['from'] . ' 00:00:00')->where('recorded_at <=', $f['to'] . ' 23:59:59')->countAllResults(),
                'tampered' => $db->table('blockchain_ledger')->where('verification_status', 'Tampered')->countAllResults(),
                'total'    => $db->table('blockchain_ledger')->countAllResults(),
            ];
        }

        return view('admin/reports', $data);
    }

    /** The same calendar day one month or year earlier, clamped to that month's length (Mar 31 → Feb 28). */
    private static function sameDayBack(string $date, string $unit): string
    {
        $t     = strtotime($date);
        $first = strtotime("first day of -1 {$unit}", $t);

        return date('Y-m-', $first) . sprintf('%02d', min((int) date('j', $t), (int) date('t', $first)));
    }

    /** @return array<string, array<string, array{count: int, amount: float}>> type => status => totals */
    private function reportSummary(array $f): array
    {
        $summary = [];
        foreach ($this->reportRecords($f, false)->select('fr.record_type, fr.approval_status, COUNT(*) AS n, COALESCE(SUM(fr.amount), 0) AS total', false)
                     ->groupBy('fr.record_type, fr.approval_status')->get()->getResultArray() as $row) {
            $summary[$row['record_type']][$row['approval_status']] = ['count' => (int) $row['n'], 'amount' => (float) $row['total']];
        }

        return $summary;
    }

    /**
     * Amount per type in day, week, month or year buckets across the period.
     * Uses the chosen status, or Approved when no status is picked, so pending
     * and rejected amounts never inflate the trend.
     *
     * @return array{unit: string, status: string, buckets: list<array{from: string, to: string, label: string, values: array<string, float>}>}
     */
    private function reportTrend(array $f): array
    {
        $status = $f['status'] !== '' ? $f['status'] : 'Approved';
        $from   = $f['from'];
        if ($f['period'] === 'all') {
            $first = $this->reportRecords(['status' => $status] + $f, false)->select('MIN(fr.transaction_date) AS d', false)->get()->getRow('d');
            $from  = $first ?: $f['to'];
        }

        $days = (int) ((strtotime($f['to']) - strtotime($from)) / 86400) + 1;
        $unit = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : ($days <= 1100 ? 'month' : 'year'));
        // Fixed SQL per unit; nothing here comes from the request.
        $key = [
            'day'   => "DATE_FORMAT(fr.transaction_date, '%Y-%m-%d')",
            'week'  => "DATE_FORMAT(DATE_SUB(fr.transaction_date, INTERVAL WEEKDAY(fr.transaction_date) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT(fr.transaction_date, '%Y-%m-01')",
            'year'  => "DATE_FORMAT(fr.transaction_date, '%Y-01-01')",
        ][$unit];

        $sums = [];
        foreach ($this->reportRecords(['from' => $from, 'status' => $status] + $f, false)
                     ->select("{$key} AS b, fr.record_type, COALESCE(SUM(fr.amount), 0) AS total", false)
                     ->groupBy('b, fr.record_type')->get()->getResultArray() as $row) {
            $sums[$row['b']][$row['record_type']] = (float) $row['total'];
        }

        $start = match ($unit) {
            'week'  => date('Y-m-d', strtotime($from . ' -' . (date('N', strtotime($from)) - 1) . ' days')),
            'month' => date('Y-m-01', strtotime($from)),
            'year'  => date('Y-01-01', strtotime($from)),
            default => $from,
        };
        $step   = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month', 'year' => '+1 year'][$unit];
        $format = ['day' => 'M j', 'week' => 'M j', 'month' => 'M Y', 'year' => 'Y'][$unit];

        $buckets = [];
        for ($t = strtotime($start); $t <= strtotime($f['to']); $t = strtotime($step, $t)) {
            $b    = date('Y-m-d', $t);
            $end  = date('Y-m-d', strtotime($step . ' -1 day', $t));
            $buckets[] = [
                // Clamp to the report period so a drill-down never leaves it.
                'from'   => max($b, $from),
                'to'     => min($end, $f['to']),
                'label'  => ($unit === 'week' ? 'Week of ' : '') . date($format, $t),
                'values' => array_map(fn($type) => $sums[$b][$type] ?? 0.0, array_combine($f['types'], $f['types'])),
            ];
        }

        return ['unit' => $unit, 'status' => $status, 'buckets' => $buckets];
    }

    /** GET: the filtered transactions as a CSV file. */
    public function exportReport()
    {
        $this->requireAdmin();

        $f    = $this->reportFilters();
        $rows = $this->reportRecords($f)->orderBy('fr.transaction_date', 'ASC')->orderBy('fr.financial_record_id', 'ASC')->get()->getResultArray();

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Record ID', 'Date', 'Type', 'Status', 'Amount (PHP)', 'Member #', 'Member', 'Account #']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['financial_record_id'], $r['transaction_date'], $r['record_type'], $r['approval_status'],
                number_format((float) $r['amount'], 2, '.', ''), self::csvText($r['member_number']), self::csvText($r['member_name']), self::csvText($r['account_number'])]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        \App\Libraries\AuditLog::record('Exported report', null, null, "Transactions {$f['from']} to {$f['to']}, " . count($rows) . ' row(s)');

        return $this->response->download("glc-bloc-report-{$f['from']}-to-{$f['to']}.csv", $csv)->setContentType('text/csv');
    }

    /**
     * A typed or imported value for a CSV cell. Excel runs a cell that starts
     * with = + - @ (or a tab / carriage return) as a formula, so a member
     * named "=HYPERLINK(...)" would become a live link; a leading apostrophe
     * keeps it as text.
     */
    public static function csvText(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    /** GET: approved activity for a time range (JSON). */
    public function analyticsActivity()
    {
        $this->requireAdmin();

        return $this->response->setJSON((new AnalyticsService())->activity((string) $this->request->getGet('range')));
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
