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
}
