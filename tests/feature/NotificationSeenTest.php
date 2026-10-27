<?php

use App\Services\Notifier;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Opening the notification bell marks everything read except important
 * notifications, which keep the badge until they are dealt with.
 * Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class NotificationSeenTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    private array $member;
    private array $admin;

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

        $m = $this->db->table('users u')->select('u.user_id, m.member_id')->join('members m', 'm.user_id = u.user_id')
            ->where('u.username', 'jdoe')->get()->getRowArray();
        $this->member = ['user_id' => (int) $m['user_id'], 'username' => 'jdoe', 'role' => 'Member', 'member_id' => (int) $m['member_id'], 'logged_in' => true, 'last_activity' => time()];
        $a = $this->db->table('users')->where('username', 'admin')->get()->getRowArray();
        $this->admin = ['user_id' => (int) $a['user_id'], 'username' => 'admin', 'role' => 'Admin', 'is_super_admin' => true, 'logged_in' => true, 'last_activity' => time()];
    }

    private function unreadKeys(string $table, string $owner, int $id): array
    {
        $keys = array_column($this->db->table($table)->select('event_key')->where($owner, $id)->where('is_read', 0)
            ->orderBy('event_key')->get()->getResultArray(), 'event_key');
        sort($keys);

        return $keys;
    }

    public function testImportanceRule(): void
    {
        foreach (['activate-5-300000', 'contribution-rejected-1', 'payment-rejected-2', 'loan-rejected-3'] as $key) {
            $this->assertTrue(Notifier::isImportant('member', $key), $key);
        }
        foreach (['contribution-received-1', 'contribution-approved-1', 'payment-approved-2', 'loan-approved-3', 'dividend-4', 'announcement-x', 'account-active-5', null] as $key) {
            $this->assertFalse(Notifier::isImportant('member', $key), (string) $key);
        }
        foreach (['review-record-1', 'review-loan-2', 'review-request-3', 'review-dividend-4', 'ledger-tampered-2026-10-26'] as $key) {
            $this->assertTrue(Notifier::isImportant('admin', $key), $key);
        }
        $this->assertFalse(Notifier::isImportant('admin', 'loan-approved-3'));
    }

    public function testMemberBellKeepsOnlyImportantUnread(): void
    {
        $id = $this->member['member_id'];
        foreach (['contribution-received-1', 'contribution-approved-1', 'announcement-a', 'activate-1-300000', 'loan-rejected-9', 'dividend-7'] as $key) {
            Notifier::toMember($id, 'contribution', 'T ' . $key, 'M', null, $key);
        }
        $this->db->table('notifications')->insert(['member_id' => $id, 'type' => 'announcement', 'title' => 'No key', 'message' => 'M', 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $other = (int) $this->db->table('members')->where('member_id !=', $id)->get()->getRow('member_id');
        if ($other) {
            Notifier::toMember($other, 'contribution', 'Someone else', 'M', null, 'contribution-received-1');
        }

        $result = $this->withSession($this->member)->post('member/notifications/seen');

        $result->assertOK();
        $this->assertSame(['activate-1-300000', 'loan-rejected-9'], $this->unreadKeys('notifications', 'member_id', $id));
        $this->assertSame(2, json_decode($result->getJSON(), true)['unread']);
        if ($other) {
            $this->assertSame(['contribution-received-1'], $this->unreadKeys('notifications', 'member_id', $other), "Another member's notifications are untouched.");
        }
    }

    public function testAdminBellKeepsItemsStillToReview(): void
    {
        $id = $this->admin['user_id'];
        foreach (['review-loan-3', 'review-record-8', 'ledger-tampered-2026-10-26'] as $key) {
            $this->db->table('admin_notifications')->insert(['user_id' => $id, 'type' => 'loan', 'title' => $key, 'message' => 'M', 'event_key' => $key, 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }
        $this->db->table('admin_notifications')->insert(['user_id' => $id, 'type' => 'loan', 'title' => 'Info', 'message' => 'M', 'event_key' => 'info-1', 'is_read' => 0, 'created_at' => date('Y-m-d H:i:s')]);

        $result = $this->withSession($this->admin)->post('admin/notifications/seen');

        $result->assertOK();
        $this->assertSame(['ledger-tampered-2026-10-26', 'review-loan-3', 'review-record-8'], $this->unreadKeys('admin_notifications', 'user_id', $id));
        $this->assertSame(3, json_decode($result->getJSON(), true)['unread']);
    }

    public function testMemberCannotUseTheAdminEndpoint(): void
    {
        $this->withSession($this->member)->post('admin/notifications/seen')->assertRedirectTo('/login');
    }

    /** The bell's background check sees a loan application made after the page loaded. */
    public function testAdminFeedReportsANewLoanApplication(): void
    {
        $before = (int) ($this->db->table('admin_notifications')->selectMax('notification_id')->get()->getRow()->notification_id ?? 0);
        // Opened in January, with its approved PHP 300: Active, limit PHP 600.
        $this->db->table('cooperative_accounts')->where('member_id', $this->member['member_id'])
            ->update(['account_start_date' => date('Y-01-01'), 'account_status' => 'Active']);
        $account = $this->db->table('cooperative_accounts')->where('member_id', $this->member['member_id'])->get()->getRowArray();
        $this->withSession($this->member)->post('member/loans/apply', ['account_id' => $account['account_id'], 'loan_amount' => 500, 'loan_term_months' => 3]);

        $res = $this->withSession($this->admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'X-Background-Poll' => '1'])
            ->get('admin/notifications/feed?since=' . $before . '&next=admin/dashboard');
        $res->assertOK();
        $data = json_decode($res->getJSON(), true);

        $this->assertSame('Loan application to review', $data['new'][0]['title'] ?? null);
        $this->assertGreaterThan(0, $data['unread']);
        $this->assertGreaterThan($before, $data['newest']);
        $this->assertStringContainsString('Loan application to review', $data['html']);

        // Asked again with the newest id: nothing new to pop up.
        $again = $this->withSession($this->admin)->withHeaders(['X-Background-Poll' => '1'])
            ->get('admin/notifications/feed?since=' . $data['newest']);
        $this->assertSame([], json_decode($again->getJSON(), true)['new']);
    }

    /** Background checks do not keep an unattended sign-in alive. */
    public function testBackgroundCheckDoesNotCountAsActivity(): void
    {
        $session = $this->admin;
        $session['last_activity'] = time() - 600;   // idle for 10 minutes

        $this->withSession($session)->withHeaders(['X-Background-Poll' => '1'])->get('admin/notifications/feed')->assertOK();
        $this->assertSame($session['last_activity'], $_SESSION['last_activity']);

        $this->withSession($session)->withHeaders([])->get('admin/notifications/feed')->assertOK();
        $this->assertGreaterThan($session['last_activity'], $_SESSION['last_activity'], 'A normal request does count.');
    }

    public function testFeedsAreForTheirOwnRoleOnly(): void
    {
        $this->withSession($this->member)->get('admin/notifications/feed')->assertRedirectTo('/login');
        $this->withSession($this->member)->withHeaders(['X-Background-Poll' => '1'])->get('member/notifications/feed')->assertOK();
    }
}
