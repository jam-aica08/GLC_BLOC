<?php

use App\Services\AnalyticsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Analytics figures and the chart's month axis.
 * Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class AnalyticsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    protected function setUp(): void
    {
        if (config('Database')->tests['DBDriver'] !== 'MySQLi') {
            if (getenv('CI')) {
                $this->fail('CI must provide the MySQL test database (database_tests_* variables).');
            }
            $this->markTestSkipped('Needs the MySQL test database (database.tests.*).');
        }
        parent::setUp();
    }

    private function addLoan(string $status): void
    {
        $accountId = (int) $this->db->table('cooperative_accounts')->get()->getRowArray()['account_id'];
        $this->db->table('loans')->insert([
            'account_id' => $accountId, 'total_contributions' => 300, 'maximum_loan_limit' => 600,
            'loan_amount' => 100, 'interest_rate' => 2, 'interest_amount' => 2, 'loan_term_months' => 3,
            'application_date' => date('Y-m-d'), 'loan_status' => $status,
        ]);
        if ($status !== 'Pending' && $status !== 'Rejected') {
            $this->db->table('financial_records')->insert([
                'account_id' => $accountId, 'record_type' => 'Loan Disbursement', 'transaction_date' => date('Y-m-d'),
                'amount' => 100, 'approval_status' => 'Approved', 'reference_id' => $this->db->insertID(),
            ]);
        }
    }

    public function testActiveLoansCountsOnlyLoansStillBeingRepaid(): void
    {
        foreach (['Active', 'Paid', 'Paid', 'Pending', 'Rejected'] as $status) {
            $this->addLoan($status);
        }

        $this->assertSame(1, (new AnalyticsService())->chartPayload()['stats']['active_loans']);
    }

    public function testMonthAxisHasEveryMonthOnceEvenOnThe31st(): void
    {
        $payload = (new AnalyticsService())->chartPayload(12, 3, strtotime('2026-10-31 09:00'));

        $this->assertSame(
            ['Nov 2025', 'Dec 2025', 'Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'],
            $payload['labels']
        );
        $this->assertSame(['Nov 2026', 'Dec 2026', 'Jan 2027'], $payload['forecastLabels']);
    }

    public function testOutOfRangeMonthsAreClamped(): void
    {
        $service = new AnalyticsService();

        $this->assertCount(1, $service->chartPayload(-5, -3)['labels']);
        $this->assertSame([], $service->chartPayload(-5, -3)['forecast']);
        $this->assertCount(60, $service->chartPayload(5000, 99)['labels']);
        $this->assertCount(12, $service->chartPayload(5000, 99)['forecast']);
    }

    public function testDataEndpointNoLongerFailsOnANegativeRange(): void
    {
        $admin = $this->db->table('users')->where('username', 'admin')->get()->getRowArray();

        $this->withSession(['user_id' => (int) $admin['user_id'], 'username' => 'admin', 'role' => 'Admin', 'is_super_admin' => true, 'logged_in' => true])
            ->get('admin/analytics/data?months=-5')
            ->assertOK();
    }
}
