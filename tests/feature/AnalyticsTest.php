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

    private function addRecord(string $type, float $amount, string $date, string $status = 'Approved'): void
    {
        $accountId = (int) $this->db->table('cooperative_accounts')->get()->getRowArray()['account_id'];
        $this->db->table('financial_records')->insert([
            'account_id' => $accountId, 'record_type' => $type, 'transaction_date' => $date,
            'amount' => $amount, 'approval_status' => $status,
        ]);
    }

    public function testEquityLineIsTheRunningTotalOfContributionsAndDividends(): void
    {
        // The seeder adds an approved PHP 300 contribution on 2026-01-05.
        $this->addRecord('Contribution', 1000, '2025-06-10');               // before the 12 months
        $this->addRecord('Contribution', 500, '2026-02-10');
        $this->addRecord('Contribution', 200, '2026-05-20', 'Pending');     // not counted
        $this->addRecord('Loan Payment', 70, '2026-03-01');                 // not equity
        $this->addRecord('Dividend', 50, '2026-08-01');

        $payload = (new AnalyticsService())->chartPayload(12, 3, strtotime('2026-10-15'));

        $this->assertSame(
            [1000.0, 1000.0, 1300.0, 1800.0, 1800.0, 1800.0, 1800.0, 1800.0, 1800.0, 1850.0, 1850.0, 1850.0],
            $payload['actual']
        );
        $this->assertSame($payload['stats']['current_equity'], end($payload['actual']), 'Last point matches the Current Equity tile.');
    }

    public function testSeededSnapshotNoLongerZeroesItsMonth(): void
    {
        // GlcBlocSeeder stores a January 2026 snapshot without an equity figure.
        $this->seeInDatabase('analytics_snapshots', ['snapshot_date' => '2026-01-31']);

        $payload = (new AnalyticsService())->chartPayload(12, 3, strtotime('2026-10-15'));

        $this->assertSame(300.0, $payload['actual'][array_search('Jan 2026', $payload['labels'], true)]);
    }

    public function testForecastFollowsGrowingEquity(): void
    {
        foreach (['2025-12-10', '2026-02-10', '2026-04-10', '2026-06-10', '2026-08-10', '2026-10-10'] as $date) {
            $this->addRecord('Contribution', 600, $date);
        }

        $payload = (new AnalyticsService())->chartPayload(12, 3, strtotime('2026-10-15'));

        $this->assertGreaterThan(end($payload['actual']), $payload['forecast'][0]);
        $this->assertGreaterThan($payload['forecast'][0], $payload['forecast'][1]);
        $this->assertGreaterThan($payload['forecast'][1], $payload['forecast'][2]);
    }

    public function testActivityRefreshesWithinAMinuteForEveryRange(): void
    {
        foreach (array_keys(AnalyticsService::RANGES) as $range) {
            $refresh = (new AnalyticsService())->activity($range)['default_refresh'];
            $this->assertGreaterThan(0, $refresh, $range);
            $this->assertLessThanOrEqual(60, $refresh, "{$range} refreshes too slowly to look live");
        }
        $this->assertSame(30, (new AnalyticsService())->activity('24h')['default_refresh'], 'The default range');
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
