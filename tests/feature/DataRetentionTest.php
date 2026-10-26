<?php

use App\Services\RetentionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The data retention review (Config\Retention): what the daily run deletes,
 * flags and deactivates, and the super administrator's manual steps. The
 * review is run "in the future" by passing a later time, so no row needs
 * back-dating. Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class DataRetentionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    private array $superSession;
    private array $adminSession;
    private int $memberId;
    private int $userId;
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

        $super = $this->db->table('users')->where('username', 'admin')->get()->getRowArray();
        $this->superSession = ['user_id' => (int) $super['user_id'], 'username' => 'admin', 'role' => 'Admin', 'is_super_admin' => true, 'logged_in' => true];
        $this->db->table('users')->insert(['role_id' => $super['role_id'], 'username' => 'admin2', 'email' => 'admin2@example.com',
            'password_hash' => password_hash('Admin2@123', PASSWORD_DEFAULT), 'account_status' => 'Active', 'is_super_admin' => 0]);
        $this->adminSession = ['user_id' => (int) $this->db->insertID(), 'username' => 'admin2', 'role' => 'Admin', 'is_super_admin' => false, 'logged_in' => true];

        $row = $this->db->table('users u')->select('u.user_id, m.member_id, a.account_id')
            ->join('members m', 'm.user_id = u.user_id')
            ->join('cooperative_accounts a', 'a.member_id = m.member_id')
            ->where('u.username', 'jdoe')->get()->getRowArray();
        $this->userId    = (int) $row['user_id'];
        $this->memberId  = (int) $row['member_id'];
        $this->accountId = (int) $row['account_id'];

        // The member's last activity is a contribution paid today; the tests count from here.
        $this->db->table('contributions')->where('account_id', $this->accountId)->update(['payment_date' => date('Y-m-d')]);

        putenv('CRON_SECRET');
    }

    private function reviewAt(string $when): array
    {
        return (new RetentionService(null, strtotime($when)))->run();
    }

    private function member(): array
    {
        return $this->db->table('members')->where('member_id', $this->memberId)->get()->getRowArray();
    }

    public function testReviewDeletesExpiredLogsNotificationsAndRequestsButKeepsTheAuditTrail(): void
    {
        $old = date('Y-m-d H:i:s', strtotime('-200 days'));
        $this->db->table('audit_logs')->insertBatch([
            ['username' => 'jdoe', 'action' => 'Failed login', 'result' => 'Failed', 'created_at' => $old],
            ['username' => 'admin', 'action' => 'Approved loan', 'result' => 'Success', 'created_at' => $old],
            ['username' => 'jdoe', 'action' => 'Logged in', 'result' => 'Success', 'created_at' => date('Y-m-d H:i:s')],
        ]);
        $this->db->table('notifications')->insertBatch([
            ['member_id' => $this->memberId, 'type' => 'x', 'title' => 'Old read', 'message' => 'm', 'is_read' => 1, 'read_at' => $old, 'created_at' => $old],
            ['member_id' => $this->memberId, 'type' => 'x', 'title' => 'Old unread', 'message' => 'm', 'is_read' => 0, 'read_at' => null, 'created_at' => $old],
            ['member_id' => $this->memberId, 'type' => 'x', 'title' => 'Recent read', 'message' => 'm', 'is_read' => 1, 'read_at' => date('Y-m-d H:i:s'), 'created_at' => $old],
        ]);
        $this->db->table('account_requests')->insert(['member_id' => $this->memberId, 'account_type' => 'Basic', 'status' => 'Rejected',
            'reviewed_at' => date('Y-m-d H:i:s', strtotime('-400 days')), 'created_at' => $old]);
        $this->db->table('ci_sessions')->insert(['id' => 'expired-session', 'ip_address' => '127.0.0.1', 'timestamp' => date('Y-m-d H:i:s', strtotime('-3 days')), 'data' => '']);

        $summary = (new RetentionService())->run();

        $this->dontSeeInDatabase('audit_logs', ['action' => 'Failed login']);
        $this->seeInDatabase('audit_logs', ['action' => 'Approved loan']);
        $this->seeInDatabase('audit_logs', ['action' => 'Logged in']);
        $this->dontSeeInDatabase('notifications', ['title' => 'Old read']);
        $this->seeInDatabase('notifications', ['title' => 'Old unread'], 'Unread notifications are kept for a year.');
        $this->seeInDatabase('notifications', ['title' => 'Recent read']);
        $this->dontSeeInDatabase('account_requests', ['status' => 'Rejected']);
        $this->dontSeeInDatabase('ci_sessions', ['id' => 'expired-session']);
        $this->assertSame(1, $summary['sign-in log entries deleted']);
        $this->seeInDatabase('audit_logs', ['action' => 'Ran data retention review', 'username' => 'system']);
    }

    public function testInactiveMemberIsFlaggedAfterSixMonthsAndDeactivatedAfterTwelve(): void
    {
        $this->reviewAt('+7 months');
        $m = $this->member();
        $this->assertNotNull($m['retention_flagged_at']);
        $this->assertSame('Active', $m['membership_status']);
        $this->seeInDatabase('notifications', ['member_id' => $this->memberId, 'title' => 'Your membership looks inactive']);

        $this->reviewAt('+13 months');
        $this->assertSame('Inactive', $this->member()['membership_status']);
        $this->seeInDatabase('users', ['user_id' => $this->userId, 'account_status' => 'Inactive']);
        // Deactivation keeps every record.
        $this->seeInDatabase('cooperative_accounts', ['account_id' => $this->accountId]);
        $this->seeInDatabase('contributions', ['account_id' => $this->accountId]);
    }

    public function testActivityClearsTheFlag(): void
    {
        $this->reviewAt('+7 months');
        $this->db->table('users')->where('user_id', $this->userId)->update(['last_activity_at' => date('Y-m-d H:i:s', strtotime('+8 months'))]);

        $this->reviewAt('+8 months +1 day');

        $this->assertNull($this->member()['retention_flagged_at']);
    }

    public function testMemberWithAnOpenLoanIsNotDeactivated(): void
    {
        $this->db->table('loans')->insert(['account_id' => $this->accountId, 'loan_amount' => 100, 'interest_rate' => 2,
            'loan_term_months' => 3, 'application_date' => date('Y-m-d'), 'loan_status' => 'Active']);

        $this->reviewAt('+13 months');

        $this->assertSame('Active', $this->member()['membership_status']);
        $this->assertNotNull($this->member()['retention_flagged_at']);
    }

    public function testLegalHoldStopsTheReview(): void
    {
        $this->withSession($this->superSession)
            ->post('admin/retention/hold', ['hold' => '1', 'member_number' => 'MEM-001', 'reason' => 'Dividend dispute']);
        $this->assertSame(1, (int) $this->member()['legal_hold']);

        $this->reviewAt('+13 months');

        $m = $this->member();
        $this->assertSame('Active', $m['membership_status']);
        $this->assertNull($m['retention_flagged_at']);
    }

    public function testLoginDataIsRemovedOnlyFromALongDeactivatedMember(): void
    {
        $now = strtotime('+25 months');
        $service = new RetentionService(null, $now);
        $this->assertNotNull($service->removeLoginData($this->memberId), 'Refused while the membership is active.');

        $this->db->table('audit_logs')->insert(['user_id' => $this->userId, 'username' => 'jdoe', 'action' => 'Submitted contribution', 'created_at' => date('Y-m-d H:i:s')]);
        $service->run();   // deactivates (inactive for 25 months)
        $this->assertNull($service->removeLoginData($this->memberId));

        $user = $this->db->table('users')->where('user_id', $this->userId)->get()->getRowArray();
        $this->assertSame('former-' . $this->userId, $user['username']);
        $this->assertStringEndsWith('@removed.invalid', $user['email']);
        $this->assertFalse(password_verify('Member@123', $user['password_hash']));
        $this->dontSeeInDatabase('audit_logs', ['username' => 'jdoe']);
        $this->dontSeeInDatabase('notifications', ['member_id' => $this->memberId]);
        // The name and the books stay.
        $this->assertSame('John', $this->member()['first_name']);
        $this->seeInDatabase('financial_records', ['account_id' => $this->accountId]);
    }

    public function testNameIsAnonymizedOnlyAfterTheFinancialPeriod(): void
    {
        $later = new RetentionService(null, strtotime('+25 months'));
        $later->run();
        $later->removeLoginData($this->memberId);

        $this->assertStringContainsString('must be kept until', (string) $later->anonymize($this->memberId));
        $this->assertSame('John', $this->member()['first_name']);

        $lastTx = $this->db->table('financial_records')->selectMax('transaction_date')->get()->getRow()->transaction_date;
        $after  = new RetentionService(null, strtotime(((int) substr($lastTx, 0, 4) + 11) . '-01-15'));
        $this->assertNull($after->anonymize($this->memberId));

        $m = $this->member();
        $this->assertSame('Former', $m['first_name']);
        $this->assertSame('MEM-001', $m['member_number']);
        $this->seeInDatabase('financial_records', ['account_id' => $this->accountId]);
    }

    public function testSigningInRecordsActivity(): void
    {
        $this->post('login', ['username' => 'jdoe', 'password' => 'Member@123']);

        $this->assertNotNull($this->db->table('users')->where('user_id', $this->userId)->get()->getRowArray()['last_activity_at']);
    }

    public function testOnlyTheSuperAdminCanUseTheRetentionPage(): void
    {
        $this->withSession($this->adminSession)->get('admin/retention')->assertRedirectTo('/admin/dashboard');
        $this->withSession($this->adminSession)->post('admin/retention/hold', ['hold' => '1', 'member_number' => 'MEM-001', 'reason' => 'x']);
        $this->assertSame(0, (int) $this->member()['legal_hold']);

        $page = $this->withSession($this->superSession)->get('admin/retention');
        $page->assertOK();
        $page->assertSee('Members due for review');
    }

    public function testCronEndpointNeedsTheSecret(): void
    {
        $this->get('cron/retention')->assertStatus(404);

        putenv('CRON_SECRET=test-secret');
        try {
            $this->withHeaders(['Authorization' => 'Bearer wrong'])->get('cron/retention')->assertStatus(404);
            $ok = $this->withHeaders(['Authorization' => 'Bearer test-secret'])->get('cron/retention');
            $ok->assertStatus(200);
            $ok->assertJSONFragment(['ok' => true]);
        } finally {
            putenv('CRON_SECRET');
        }
    }

    public function testPrivacyNoticeIsPublic(): void
    {
        $page = $this->get('privacy');
        $page->assertOK();
        $page->assertSee('Retention schedule');
        $page->assertSee('Financial records');
    }
}
