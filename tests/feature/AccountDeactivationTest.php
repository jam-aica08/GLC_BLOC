<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Deactivating one account of a member at a time: directly by an admin, or
 * by a member's request that an admin approves. Needs the MySQL test
 * database, like AuthSecurityTest.
 *
 * @internal
 */
final class AccountDeactivationTest extends CIUnitTestCase
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
    private int $firstAccount;
    private int $secondAccount;

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
        $this->firstAccount  = (int) $row['account_id'];
        $this->memberSession = ['user_id' => (int) $row['user_id'], 'username' => 'jdoe', 'role' => 'Member', 'member_id' => $this->memberId, 'logged_in' => true];

        // Opened in January, so its approved PHP 300 keeps it Active.
        $this->db->table('cooperative_accounts')->where('account_id', $this->firstAccount)
            ->update(['account_start_date' => date('Y-01-01'), 'account_status' => 'Active']);

        // A second account of the same member.
        $this->db->table('cooperative_accounts')->insert([
            'member_id'            => $this->memberId,
            'account_number'       => 'ACC-2026-900',
            'account_type'         => 'Basic',
            'account_start_date'   => date('Y-m-d'),
            'monthly_contribution' => 300,
            'account_status'       => 'Pending',
        ]);
        $this->secondAccount = (int) $this->db->insertID();
    }

    private function accountStatus(int $accountId): string
    {
        return $this->db->table('cooperative_accounts')->where('account_id', $accountId)->get()->getRowArray()['account_status'];
    }

    public function testAdminDeactivatesOneAccountAndLeavesTheRestAlone(): void
    {
        $this->withSession($this->adminSession)
            ->post("admin/members/accounts/{$this->secondAccount}/deactivate", ['reason' => 'Member asked at the office'])
            ->assertRedirectTo('/admin/members');

        $account = $this->db->table('cooperative_accounts')->where('account_id', $this->secondAccount)->get()->getRowArray();
        $this->assertSame('Inactive', $account['account_status']);
        $this->assertSame('Member asked at the office', $account['deactivation_reason']);
        $this->assertNotNull($account['deactivated_at']);

        $this->assertSame('Active', $this->accountStatus($this->firstAccount), 'The other account is untouched.');
        $this->seeInDatabase('members', ['member_id' => $this->memberId, 'membership_status' => 'Active']);
        $this->seeInDatabase('users', ['username' => 'jdoe', 'account_status' => 'Active']);
        $this->seeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Account ACC-2026-900 deactivated']);
    }

    /** Deactivated is not closed: contributions are still accepted; new loans are not. */
    public function testDeactivatedAccountTakesContributionsButNoLoans(): void
    {
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");
        $before = $this->db->table('contributions')->countAllResults();

        $this->withSession($this->memberSession)->post('member/contributions/submit',
            ['account_id' => $this->firstAccount, 'contribution_period' => date('Y-m'), 'amount' => 300]);
        $this->withSession($this->memberSession)->post('member/loans/apply',
            ['account_id' => $this->firstAccount, 'loan_amount' => 100, 'loan_term_months' => 3]);

        $this->assertSame($before + 1, $this->db->table('contributions')->countAllResults());
        $this->assertSame('Inactive', $this->accountStatus($this->firstAccount), 'Contributing does not reactivate it.');
        $this->assertSame(0, $this->db->table('loans')->where('account_id', $this->firstAccount)->countAllResults());
    }

    /** A deactivated account gets no contribution reminders. */
    public function testDeactivatedAccountGetsNoContributionReminders(): void
    {
        // The second account is joining, so it has an "activate" reminder.
        $this->withSession($this->memberSession)->get('member/dashboard');
        $this->seeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Activate account ACC-2026-900']);

        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->secondAccount}/deactivate");
        $this->withSession($this->memberSession)->get('member/dashboard');

        $this->dontSeeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Activate account ACC-2026-900']);
    }

    public function testAccountWithAnOpenLoanCannotBeDeactivated(): void
    {
        $this->db->table('loans')->insert([
            'account_id' => $this->firstAccount, 'loan_amount' => 100, 'interest_rate' => 2, 'loan_term_months' => 3,
            'application_date' => date('Y-m-d'), 'loan_status' => 'Active',
        ]);

        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");

        $this->assertSame('Active', $this->accountStatus($this->firstAccount));
    }

    /** Approved contributions on the first account for each YYYY-MM given. */
    private function paid(array $periods): void
    {
        foreach ($periods as $period) {
            $this->db->table('contributions')->insert(['account_id' => $this->firstAccount, 'contribution_period' => $period,
                'amount' => 300, 'payment_date' => date('Y-m-d'), 'payment_status' => 'Approved', 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** YYYY-MM of $n months ago. */
    private static function monthsAgo(int $n): string
    {
        return date('Y-m', strtotime("first day of -{$n} months"));
    }

    public function testReactivationWaitsForTheMissedMonthsToBePaid(): void
    {
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");
        // Stopped three months ago: that month up to this one is four months, PHP 1,200.
        $this->db->table('cooperative_accounts')->where('account_id', $this->firstAccount)
            ->update(['deactivated_at' => self::monthsAgo(3) . '-10 09:00:00']);

        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/reactivate");

        $account = $this->db->table('cooperative_accounts')->where('account_id', $this->firstAccount)->get()->getRowArray();
        $this->assertSame('Pending', $account['account_status'], 'Not active until the missed months are paid.');
        $this->assertSame(self::monthsAgo(3), $account['reactivation_from']);
        $this->assertNull($account['deactivated_at']);
        $this->assertSame(1200.0, model(\App\Models\CooperativeAccountModel::class)->reactivationArrears($account));

        // Three of the four months: still Pending.
        $this->paid([self::monthsAgo(3), self::monthsAgo(2), self::monthsAgo(1)]);
        model(\App\Models\CooperativeAccountModel::class)->syncStatuses();
        $this->assertSame('Pending', $this->accountStatus($this->firstAccount));

        // The current month too: Active again.
        $this->paid([date('Y-m')]);
        model(\App\Models\CooperativeAccountModel::class)->syncStatuses();
        $account = $this->db->table('cooperative_accounts')->where('account_id', $this->firstAccount)->get()->getRowArray();
        $this->assertSame('Active', $account['account_status']);
        $this->assertNull($account['reactivation_from']);
    }

    public function testDeactivatingAgainBeforeSettlingKeepsTheEarlierMissedMonths(): void
    {
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");
        $this->db->table('cooperative_accounts')->where('account_id', $this->firstAccount)
            ->update(['deactivated_at' => self::monthsAgo(5) . '-01 09:00:00']);
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/reactivate");

        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/reactivate");

        $account = $this->db->table('cooperative_accounts')->where('account_id', $this->firstAccount)->get()->getRowArray();
        $this->assertSame(self::monthsAgo(5), $account['reactivation_from']);
    }

    public function testReactivatingAccountShowsWhatIsLeftToSettle(): void
    {
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/reactivate");

        $page = $this->withSession($this->memberSession)->get('member/contributions');
        $page->assertOK();
        $page->assertSee('Reactivating');
    }

    public function testMemberRequestIsAppliedOnlyWhenAnAdminApprovesIt(): void
    {
        $this->withSession($this->memberSession)
            ->post("member/profile/accounts/{$this->secondAccount}/deactivate", ['reason' => 'No longer needed'])
            ->assertRedirectTo('/member/profile');

        $request = $this->db->table('account_requests')->where('account_id', $this->secondAccount)->get()->getRowArray();
        $this->assertSame('Deactivate', $request['request_type']);
        $this->assertSame('Pending', $request['status']);
        $this->assertSame('Pending', $this->accountStatus($this->secondAccount), 'Nothing changes before approval.');

        $this->withSession($this->adminSession)->post("admin/members/requests/{$request['request_id']}/approve");

        $this->assertSame('Inactive', $this->accountStatus($this->secondAccount));
        $this->seeInDatabase('account_requests', ['request_id' => $request['request_id'], 'status' => 'Approved']);
        $this->assertSame('Active', $this->accountStatus($this->firstAccount));
    }

    public function testRejectedRequestLeavesTheAccountOpen(): void
    {
        $this->withSession($this->memberSession)->post("member/profile/accounts/{$this->secondAccount}/deactivate");
        $request = $this->db->table('account_requests')->where('account_id', $this->secondAccount)->get()->getRowArray();

        $this->withSession($this->adminSession)->post("admin/members/requests/{$request['request_id']}/reject");

        $this->assertSame('Pending', $this->accountStatus($this->secondAccount));
        $this->seeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Account request rejected']);
    }

    public function testMemberCanAskToReactivateADeactivatedAccount(): void
    {
        $this->withSession($this->adminSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");

        $this->withSession($this->memberSession)->post("member/profile/accounts/{$this->firstAccount}/reactivate");
        $request = $this->db->table('account_requests')->where('account_id', $this->firstAccount)->get()->getRowArray();
        $this->assertSame('Reactivate', $request['request_type']);

        $this->withSession($this->adminSession)->post("admin/members/requests/{$request['request_id']}/approve");
        // Reactivated, then Active once this month's missed contribution is paid.
        $this->assertSame('Pending', $this->accountStatus($this->firstAccount));
        $this->paid([date('Y-m')]);
        model(\App\Models\CooperativeAccountModel::class)->syncStatuses();
        $this->assertSame('Active', $this->accountStatus($this->firstAccount));
    }

    public function testMemberCannotRequestChangesToAnotherMembersAccount(): void
    {
        $this->db->table('users')->insert(['role_id' => 2, 'username' => 'other', 'email' => 'other@example.com',
            'password_hash' => password_hash('Other@123', PASSWORD_DEFAULT), 'account_status' => 'Active']);
        $this->db->table('members')->insert(['user_id' => $this->db->insertID(), 'member_number' => 'MEM-900',
            'first_name' => 'Other', 'last_name' => 'Member', 'membership_date' => date('Y-m-d'), 'membership_status' => 'Active']);
        $this->db->table('cooperative_accounts')->insert(['member_id' => $this->db->insertID(), 'account_number' => 'ACC-2026-901',
            'account_type' => 'Basic', 'account_start_date' => date('Y-m-d'), 'account_status' => 'Pending']);
        $theirs = (int) $this->db->insertID();

        $this->withSession($this->memberSession)->post("member/profile/accounts/{$theirs}/deactivate");

        $this->dontSeeInDatabase('account_requests', ['account_id' => $theirs]);
        $this->assertSame('Pending', $this->accountStatus($theirs));
    }

    public function testMembersCannotDeactivateAccountsDirectly(): void
    {
        $this->withSession($this->memberSession)->post("admin/members/accounts/{$this->firstAccount}/deactivate");

        $this->assertSame('Active', $this->accountStatus($this->firstAccount));
    }

    public function testMembersPageAndProfileShowTheActions(): void
    {
        $admin = $this->withSession($this->adminSession)->get('admin/members');
        $admin->assertOK();
        $admin->assertSee('Deactivating an account does not close it');

        $member = $this->withSession($this->memberSession)->get('member/profile');
        $member->assertOK();
        $member->assertSee('Request deactivation');
    }
}
