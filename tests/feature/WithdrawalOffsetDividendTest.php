<?php

use App\Services\ContributionBalance;
use App\Services\DividendService;
use App\Services\LoanService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The cooperative's money policies: flexible withdrawals, no withdrawal with
 * an outstanding loan, dividends kept after deactivation, loan offsets from
 * contributions, and the 3% administration share of dividends. Needs the
 * MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class WithdrawalOffsetDividendTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    private array $adminSession;
    private array $memberSession;
    private int $memberId;
    private int $accountId;

    protected function setUp(): void
    {
        if (config('Database')->tests['DBDriver'] !== 'MySQLi') {
            if (getenv('CI')) {
                $this->fail('CI must provide the MySQL test database (database_tests_* variables).');
            }
            $this->markTestSkipped('Needs the MySQL test database (database.tests.*).');
        }
        parent::setUp();

        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_filter($filters->globals['before'], fn($f) => $f !== 'csrf'));

        $admin = $this->db->table('users')->where('username', 'admin')->get()->getRowArray();
        $this->adminSession = ['user_id' => (int) $admin['user_id'], 'username' => 'admin', 'role' => 'Admin', 'is_super_admin' => true, 'logged_in' => true];

        $row = $this->db->table('users u')->select('u.user_id, m.member_id, a.account_id')
            ->join('members m', 'm.user_id = u.user_id')
            ->join('cooperative_accounts a', 'a.member_id = m.member_id')
            ->where('u.username', 'jdoe')->get()->getRowArray();
        $this->memberId      = (int) $row['member_id'];
        $this->accountId     = (int) $row['account_id'];
        $this->memberSession = ['user_id' => (int) $row['user_id'], 'username' => 'jdoe', 'role' => 'Member', 'member_id' => $this->memberId, 'logged_in' => true];

        // Opened in January with PHP 300 approved: Active, PHP 300 held.
        $this->db->table('cooperative_accounts')->where('account_id', $this->accountId)
            ->update(['account_start_date' => date('Y-01-01'), 'account_status' => 'Active']);
    }

    private function requestWithdrawal(float $amount, ?int $accountId = null)
    {
        return $this->withSession($this->memberSession)->post('member/withdrawals/request',
            ['account_id' => $accountId ?? $this->accountId, 'amount' => $amount, 'method' => 'GCash', 'reason' => 'School fees']);
    }

    private function latestWithdrawal(): ?array
    {
        return $this->db->table('withdrawals')->orderBy('withdrawal_id', 'DESC')->get()->getRowArray();
    }

    /** An approved, released loan on the member's account; $releasedAgo in the past makes months overdue. */
    private function activeLoan(float $amount, string $releasedAgo = 'now'): int
    {
        $this->db->table('loans')->insert([
            'account_id' => $this->accountId, 'loan_amount' => $amount, 'interest_rate' => 2, 'interest_amount' => round($amount * 0.02, 2),
            'loan_term_months' => 3, 'application_date' => date('Y-m-d'), 'loan_status' => 'Pending',
        ]);
        $loanId = (int) $this->db->insertID();
        $this->withSession($this->adminSession)->post("admin/loans/approve/{$loanId}", ['release_date' => date('Y-m-d', strtotime($releasedAgo))]);

        return $loanId;
    }

    // ── 1. Flexible withdrawals ──────────────────────────────────────────

    public function testMemberWithdrawsAvailableContributionsAfterApproval(): void
    {
        $this->requestWithdrawal(200)->assertRedirectTo('/member/withdrawals');

        $w = $this->latestWithdrawal();
        $this->assertSame('Pending', $w['status']);
        $this->seeInDatabase('financial_records', ['financial_record_id' => $w['financial_record_id'], 'record_type' => 'Withdrawal', 'approval_status' => 'Pending']);
        $this->assertSame(100.0, ContributionBalance::forAccount($this->accountId)['available'], 'A waiting request is reserved.');

        $this->withSession($this->adminSession)->post("admin/withdrawals/{$w['withdrawal_id']}/approve");

        $this->seeInDatabase('withdrawals', ['withdrawal_id' => $w['withdrawal_id'], 'status' => 'Approved']);
        $this->seeInDatabase('financial_records', ['financial_record_id' => $w['financial_record_id'], 'approval_status' => 'Approved']);
        $this->seeInDatabase('blockchain_ledger', ['financial_record_id' => $w['financial_record_id']]);
        $this->assertSame(100.0, ContributionBalance::forAccount($this->accountId)['balance']);
        $this->assertSame(200.0, (new LoanService())->checkMaxLoanLimit($this->accountId), 'The loan limit follows what the account still holds.');
        $this->seeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Withdrawal approved']);
    }

    public function testWithdrawalCannotExceedAvailableOrRepeatWhileWaiting(): void
    {
        $this->requestWithdrawal(300.01);
        $this->assertNull($this->latestWithdrawal());

        $this->requestWithdrawal(100);
        $this->requestWithdrawal(100);
        $this->assertSame(1, $this->db->table('withdrawals')->countAllResults(), 'One waiting request per account.');
    }

    public function testRejectedWithdrawalReleasesTheReservedAmount(): void
    {
        $this->requestWithdrawal(250);
        $w = $this->latestWithdrawal();

        $this->withSession($this->adminSession)->post("admin/withdrawals/{$w['withdrawal_id']}/reject");

        $this->seeInDatabase('withdrawals', ['withdrawal_id' => $w['withdrawal_id'], 'status' => 'Rejected']);
        $this->assertSame(300.0, ContributionBalance::forAccount($this->accountId)['available']);
    }

    public function testMemberCannotWithdrawFromAnotherMembersAccount(): void
    {
        $this->db->table('users')->insert(['role_id' => 2, 'username' => 'other', 'email' => 'other@example.com',
            'password_hash' => password_hash('Other@123', PASSWORD_DEFAULT), 'account_status' => 'Active']);
        $this->db->table('members')->insert(['user_id' => $this->db->insertID(), 'member_number' => 'MEM-900',
            'first_name' => 'Other', 'last_name' => 'Member', 'membership_date' => date('Y-m-d'), 'membership_status' => 'Active']);
        $this->db->table('cooperative_accounts')->insert(['member_id' => $this->db->insertID(), 'account_number' => 'ACC-2026-901',
            'account_type' => 'Basic', 'account_start_date' => date('Y-m-d'), 'account_status' => 'Active']);

        $this->requestWithdrawal(10, (int) $this->db->insertID());

        $this->assertNull($this->latestWithdrawal());
    }

    // ── 2. No withdrawal with an outstanding loan ───────────────────────

    public function testOutstandingLoanBlocksWithdrawalRequests(): void
    {
        $this->activeLoan(300);

        $this->requestWithdrawal(50);

        $this->assertNull($this->latestWithdrawal());
        $this->withSession($this->memberSession)->get('member/withdrawals')->assertSee('Withdrawals are on hold');
    }

    public function testLoanTakenAfterTheRequestBlocksItsApproval(): void
    {
        $this->requestWithdrawal(50);
        $w = $this->latestWithdrawal();
        $this->activeLoan(300);

        $this->withSession($this->adminSession)->post("admin/withdrawals/{$w['withdrawal_id']}/approve");

        $this->seeInDatabase('withdrawals', ['withdrawal_id' => $w['withdrawal_id'], 'status' => 'Pending']);
    }

    public function testWithdrawalIsAllowedAgainOnceTheLoanIsPaid(): void
    {
        $loanId = $this->activeLoan(300);
        $this->db->table('loans')->where('loan_id', $loanId)->update(['loan_status' => 'Paid']);

        $this->requestWithdrawal(50);

        $this->assertNotNull($this->latestWithdrawal());
    }

    // ── 3. Deactivated accounts keep their dividends ───────────────────

    /** Approved PHP 300 contributions for months 1..$months of $year on an account. */
    private function contributed(int $accountId, int $year, int $months): void
    {
        for ($m = 1; $m <= $months; $m++) {
            $this->db->table('contributions')->insert(['account_id' => $accountId, 'contribution_period' => sprintf('%04d-%02d', $year, $m),
                'amount' => 300, 'payment_date' => date('Y-m-d'), 'payment_status' => 'Approved', 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function poolForThisYear(): void
    {
        $this->db->table('loans')->insert(['account_id' => $this->accountId, 'loan_amount' => 5000, 'interest_rate' => 2, 'interest_amount' => 100,
            'loan_term_months' => 3, 'application_date' => date('Y-m-d'), 'approval_date' => date('Y-m-d'), 'loan_status' => 'Paid']);
    }

    public function testOnlyAccountsWithTheFullYearOfContributionsAreEligible(): void
    {
        $year = (int) date('Y');
        $this->poolForThisYear();
        // jdoe's account: deactivated, but with all 12 months of the year paid.
        $this->db->table('contributions')->where('account_id', $this->accountId)->delete();
        $this->contributed($this->accountId, $year, 12);
        $this->db->table('cooperative_accounts')->where('account_id', $this->accountId)->update(['account_status' => 'Inactive']);
        // An Active account with only 11 months paid.
        $this->db->table('cooperative_accounts')->insert(['member_id' => $this->memberId, 'account_number' => 'ACC-2026-950',
            'account_type' => 'Basic', 'account_start_date' => "{$year}-01-01", 'account_status' => 'Active']);
        $short = (int) $this->db->insertID();
        $this->contributed($short, $year, 11);

        $plan = (new DividendService())->plan($year);

        $ids = array_map('intval', array_column($plan['accounts'], 'account_id'));
        $this->assertContains($this->accountId, $ids, 'Deactivation does not disqualify an account that completed PHP 3,600.');
        $this->assertNotContains($short, $ids, 'PHP 3,300 is below the PHP 3,600 requirement, even for an Active account.');
        $this->assertSame(1, $plan['inactive_count']);
        $this->assertSame(1, $plan['below_count']);
    }

    public function testDashboardEstimateCountsAccountsOnTrackThisYear(): void
    {
        $year = (int) date('Y');
        $this->poolForThisYear();
        $this->db->table('contributions')->where('account_id', $this->accountId)->delete();
        $this->contributed($this->accountId, $year, (int) date('n'));   // every month so far

        $this->assertCount(1, (new DividendService())->plan($year, true)['accounts']);
        if ((int) date('n') < 12) {
            $this->assertCount(0, (new DividendService())->plan($year)['accounts'], 'A distribution needs the full PHP 3,600.');
        }
    }

    // ── 4. Loan offset ─────────────────────────────────────────────────

    public function testOffsetSettlesAMissedLoanFromContributions(): void
    {
        $loanId = $this->activeLoan(300, '-75 days');   // months 1 and 2 are past due and unpaid
        $before = (new LoanService())->schedule($loanId)['summary']['balance'];

        $this->withSession($this->adminSession)->post("admin/loans/{$loanId}/offset", ['amount' => '', 'confirm' => 'OFFSET'])
             ->assertRedirectTo('/admin/loans');

        // All PHP 300 held goes to the loan (balance PHP 306 incl. interest).
        $payment =$this->db->table('loan_payments')->where('loan_id', $loanId)->get()->getRowArray();
        $this->assertSame('Contribution offset', $payment['payment_method']);
        $this->assertSame('Approved', $payment['payment_status']);
        $this->assertEqualsWithDelta(300.0, (float) $payment['amount_paid'], 0.001);
        $this->assertEqualsWithDelta($before - 300, (new LoanService())->schedule($loanId)['summary']['balance'], 0.001);

        $offset = $this->db->table('financial_records')->where('record_type', 'Loan Offset')->get()->getRowArray();
        $this->assertSame('Approved', $offset['approval_status']);
        $this->seeInDatabase('blockchain_ledger', ['financial_record_id' => $offset['financial_record_id']]);
        $this->assertSame(0.0, ContributionBalance::forAccount($this->accountId)['balance']);
        $this->seeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Loan offset applied']);
    }

    /**
     * Regression: with two months missed in a row, the first month's unpaid
     * amount used to be dropped when the second rolled over (balance PHP 200
     * instead of PHP 306), silently forgiving part of the loan.
     */
    public function testMissedMonthsInARowKeepTheWholeDebt(): void
    {
        $loanId = $this->activeLoan(300, '-75 days');

        $summary = (new LoanService())->schedule($loanId)['summary'];

        $this->assertEqualsWithDelta(306.0, $summary['balance'], 0.001);
        $last = $this->db->table('loan_installments')->where('loan_id', $loanId)->where('installment_number', 3)->get()->getRowArray();
        $this->assertEqualsWithDelta(206.0, (float) $last['carried_over'], 0.001, 'Month 3 carries months 1 and 2.');
        $this->assertEqualsWithDelta(206.0, (new \App\Services\LoanOffsetService())->preview($loanId)['overdue'], 0.001);
    }

    /** Loans already damaged by the old bug are found and put right by Apply Database Updates. */
    public function testLostCarryOverIsDetectedAndRestored(): void
    {
        $loanId = $this->activeLoan(300, '-75 days');
        (new LoanService())->schedule($loanId);
        // What the old code left behind: month 3 only carried month 2's own PHP 100.
        $this->db->table('loan_installments')->where('loan_id', $loanId)->where('installment_number', 3)
            ->update(['carried_over' => 100.00, 'remaining_balance' => 200.00]);
        $this->assertSame([$loanId => 106.0], LoanService::lostCarryOver());

        $this->assertSame([$loanId => 106.0], LoanService::repairLostCarryOver());

        $this->assertSame([], LoanService::lostCarryOver());
        $this->assertEqualsWithDelta(306.0, (new LoanService())->schedule($loanId)['summary']['balance'], 0.001);
    }

    public function testNoOffsetWithoutAMissedPayment(): void
    {
        $loanId = $this->activeLoan(300);   // released today: nothing due yet

        $this->withSession($this->adminSession)->post("admin/loans/{$loanId}/offset", ['amount' => '100', 'confirm' => 'OFFSET']);

        $this->dontSeeInDatabase('financial_records', ['record_type' => 'Loan Offset']);
    }

    public function testOffsetNeedsConfirmationAndAnAdmin(): void
    {
        $loanId = $this->activeLoan(300, '-75 days');

        $this->withSession($this->adminSession)->post("admin/loans/{$loanId}/offset", ['amount' => '100', 'confirm' => '']);
        $this->withSession($this->memberSession)->post("admin/loans/{$loanId}/offset", ['amount' => '100', 'confirm' => 'OFFSET']);

        $this->dontSeeInDatabase('financial_records', ['record_type' => 'Loan Offset']);
    }

    public function testOffsetCannotTakeMoreThanAvailable(): void
    {
        $loanId = $this->activeLoan(300, '-75 days');

        $this->withSession($this->adminSession)->post("admin/loans/{$loanId}/offset", ['amount' => '300.01', 'confirm' => 'OFFSET']);

        $this->dontSeeInDatabase('financial_records', ['record_type' => 'Loan Offset']);
    }

    // ── 5. 3% administration share ─────────────────────────────────────

    public function testThreePercentOfThePoolGoesToAdministration(): void
    {
        $this->poolForThisYear();
        $year = (int) date('Y');
        $this->db->table('contributions')->where('account_id', $this->accountId)->delete();
        $this->contributed($this->accountId, $year, 12);

        $plan = (new DividendService())->plan($year);
        $this->assertSame(100.0, (float) $plan['profit_pool']);
        $this->assertSame(3.0, (float) $plan['admin_share']);
        $this->assertSame(97.0, (float) $plan['members_pool']);
        $this->assertSame(97.0, (float) $plan['total_distributed'], 'Members share exactly the remaining 97%.');

        [$id, $error] = (new DividendService())->create($year, date('Y-m-d'), $this->adminSession['user_id'], 100.0, count($plan['accounts']));
        $this->assertNull($error);
        $this->seeInDatabase('dividend_distributions', ['distribution_id' => $id, 'admin_share' => 3.00, 'admin_share_rate' => 3.00, 'total_distributed' => 97.00]);
        $this->assertEqualsWithDelta(97.0, (float) $this->db->table('dividends')->selectSum('dividend_amount')->where('distribution_id', $id)->get()->getRow()->dividend_amount, 0.001);
    }

    public function testPagesRender(): void
    {
        $this->requestWithdrawal(50);
        foreach (['admin/withdrawals', 'admin/loans', 'admin/dividends', 'admin/dashboard', 'admin/reports'] as $page) {
            $this->withSession($this->adminSession)->get($page)->assertOK();
        }
        foreach (['member/withdrawals', 'member/dashboard', 'member/profile'] as $page) {
            $this->withSession($this->memberSession)->get($page)->assertOK();
        }
    }
}
