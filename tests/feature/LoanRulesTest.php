<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Loan application rules enforced on the server (audit defect D-09).
 * Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class LoanRulesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    private array $memberSession;
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

        $row = $this->db->table('users u')->select('u.user_id, m.member_id, a.account_id')
            ->join('members m', 'm.user_id = u.user_id')
            ->join('cooperative_accounts a', 'a.member_id = m.member_id')
            ->where('u.username', 'jdoe')->get()->getRowArray();
        $this->accountId = (int) $row['account_id'];
        $this->memberSession = ['user_id' => (int) $row['user_id'], 'username' => 'jdoe', 'role' => 'Member', 'member_id' => (int) $row['member_id'], 'logged_in' => true];

        // Opened in January, so its approved contribution is enough to make it Active.
        $this->db->table('cooperative_accounts')->where('account_id', $this->accountId)
            ->update(['account_start_date' => date('Y-01-01'), 'account_status' => 'Active']);
    }

    private function apply(float $amount, int $term = 3)
    {
        return $this->withSession($this->memberSession)
            ->post('member/loans/apply', ['account_id' => $this->accountId, 'loan_amount' => $amount, 'loan_term_months' => $term]);
    }

    private function openLoans(): int
    {
        return $this->db->table('loans')->where('account_id', $this->accountId)
            ->whereIn('loan_status', ['Pending', 'Approved', 'Active'])->countAllResults();
    }

    public function testFirstApplicationWithinTheLimitIsAccepted(): void
    {
        $this->apply(300)->assertRedirectTo('/member/loans');
        $this->assertSame(1, $this->openLoans());
    }

    public function testSecondApplicationIsRefusedWhileOneIsOpen(): void
    {
        $this->apply(300);
        $this->apply(300);

        $this->assertSame(1, $this->openLoans(), 'Only one loan may be pending or active per member.');
    }

    public function testAnotherLoanIsAllowedOnceThePreviousOneIsPaid(): void
    {
        $this->apply(300);
        $this->db->table('loans')->where('account_id', $this->accountId)->update(['loan_status' => 'Paid']);
        $this->apply(300);

        $this->assertSame(1, $this->openLoans());
    }

    public function testAmountAboveTwiceTheContributionsIsRefused(): void
    {
        $limit = (float) $this->db->table('financial_records')->selectSum('amount', 's')
            ->where(['account_id' => $this->accountId, 'record_type' => 'Contribution', 'approval_status' => 'Approved'])
            ->get()->getRow('s') * 2;
        $this->apply($limit + 1);

        $this->assertSame(0, $this->openLoans());
    }

    public function testApprovalIsRefusedWhenTheLoanIsNowAboveTheLimit(): void
    {
        $this->apply(300)->assertRedirectTo('/member/loans');
        $loanId = (int) $this->db->table('loans')->where('account_id', $this->accountId)->get()->getRow('loan_id');

        // The contribution behind the limit is rejected after the application.
        $this->db->table('financial_records')->where(['account_id' => $this->accountId, 'record_type' => 'Contribution'])
            ->update(['approval_status' => 'Rejected']);

        $admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'Admin', 'logged_in' => true];
        $this->withSession($admin)->post('admin/loans/approve/' . $loanId)->assertRedirectTo('/admin/loans');

        $this->seeInDatabase('loans', ['loan_id' => $loanId, 'loan_status' => 'Pending']);
        $this->dontSeeInDatabase('loan_installments', ['loan_id' => $loanId]);
    }

    public function testApprovalWithinTheLimitStillWorks(): void
    {
        $this->apply(300);
        $loanId = (int) $this->db->table('loans')->where('account_id', $this->accountId)->get()->getRow('loan_id');

        $admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'Admin', 'logged_in' => true];
        $this->withSession($admin)->post('admin/loans/approve/' . $loanId)->assertRedirectTo('/admin/loans');

        $this->seeInDatabase('loans', ['loan_id' => $loanId, 'loan_status' => 'Active']);
        $this->seeInDatabase('loan_installments', ['loan_id' => $loanId, 'installment_number' => 1]);
    }

    public function testInvalidTermIsRefused(): void
    {
        $this->apply(300, 7);

        $this->assertSame(0, $this->openLoans());
    }

    public function testAmountIsKeptToTheCentavo(): void
    {
        $this->apply(300.456);

        $loan = $this->db->table('loans')->where('account_id', $this->accountId)->get()->getRowArray();
        $this->assertSame('300.46', $loan['loan_amount']);
        $this->assertSame('6.01', $loan['interest_amount'], '2% of 300.46, not of 300.456');
    }

    public function testAmountBelowTheMinimumIsRefused(): void
    {
        $this->apply(299.99);
        $this->assertSame(0, $this->openLoans());
        $this->assertSame('The minimum loan is PHP 300.00.', session()->getFlashdata('error'));

        $this->apply(300)->assertRedirectTo('/member/loans');
        $this->assertSame(1, $this->openLoans(), 'PHP 300 is allowed');
    }

    public function testApprovalRefusesAnOlderApplicationBelowTheMinimum(): void
    {
        // An application saved before the minimum existed.
        $this->db->table('loans')->insert(['account_id' => $this->accountId, 'total_contributions' => 300, 'maximum_loan_limit' => 600,
            'loan_amount' => 100, 'interest_rate' => 2, 'interest_amount' => 2, 'loan_term_months' => 3,
            'application_date' => date('Y-m-d'), 'loan_status' => 'Pending']);
        $loanId = (int) $this->db->insertID();

        $admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'Admin', 'logged_in' => true];
        $this->withSession($admin)->post('admin/loans/approve/' . $loanId)->assertRedirectTo('/admin/loans');

        $this->seeInDatabase('loans', ['loan_id' => $loanId, 'loan_status' => 'Pending']);
        $this->dontSeeInDatabase('loan_installments', ['loan_id' => $loanId]);
        $this->assertStringContainsString('below the minimum loan of PHP 300.00', (string) session()->getFlashdata('error'));
    }

    public function testOverLimitMessageShowsPesoAmounts(): void
    {
        $this->apply(999999.5);

        $this->assertStringContainsString('(PHP 999,999.50)', (string) session()->getFlashdata('error'));
    }

    public function testApprovalIgnoresAnInvalidReleaseDate(): void
    {
        $this->apply(300);
        $loanId = (int) $this->db->table('loans')->where('account_id', $this->accountId)->get()->getRow('loan_id');

        $admin = ['user_id' => 1, 'username' => 'admin', 'role' => 'Admin', 'logged_in' => true];
        $this->withSession($admin)->post('admin/loans/approve/' . $loanId, ['release_date' => 'yesterday-ish'])->assertRedirectTo('/admin/loans');

        $this->seeInDatabase('loans', ['loan_id' => $loanId, 'release_date' => date('Y-m-d')]);
        $first = $this->db->table('loan_installments')->where(['loan_id' => $loanId, 'installment_number' => 1])->get()->getRowArray();
        $this->assertSame(date('Y-m', strtotime('first day of next month')), substr($first['due_date'], 0, 7));
    }
}
