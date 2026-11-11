<?php

use App\Services\LedgerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Superadmin-controlled member password reset: only the super administrator
 * can reset, the temporary password is checked and hashed, the member must
 * choose a new one at the next sign-in, and the reset is recorded in the
 * SHA-256 ledger chain and the audit log without any password.
 * Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class PasswordResetTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    private const TEMP = 'Temp#Pass42';

    private array $superSession;
    private array $adminSession;
    private array $memberSession;
    private int $memberId;
    private int $memberUserId;

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

        $this->db->table('users')->insert([
            'role_id' => $super['role_id'], 'username' => 'admin2', 'email' => 'admin2@example.com',
            'password_hash' => password_hash('Admin2@123', PASSWORD_DEFAULT), 'account_status' => 'Active', 'is_super_admin' => 0,
        ]);
        $this->adminSession = ['user_id' => (int) $this->db->insertID(), 'username' => 'admin2', 'role' => 'Admin', 'is_super_admin' => false, 'logged_in' => true];

        $row = $this->db->table('users u')->select('u.user_id, m.member_id')
            ->join('members m', 'm.user_id = u.user_id')->where('u.username', 'jdoe')->get()->getRowArray();
        $this->memberId      = (int) $row['member_id'];
        $this->memberUserId  = (int) $row['user_id'];
        $this->memberSession = ['user_id' => $this->memberUserId, 'username' => 'jdoe', 'role' => 'Member', 'member_id' => $this->memberId, 'logged_in' => true];
    }

    private function hash(): string
    {
        return $this->db->table('users')->where('user_id', $this->memberUserId)->get()->getRowArray()['password_hash'];
    }

    private function reset(array $session, array $post = [])
    {
        return $this->withSession($session)->post("admin/members/{$this->memberId}/reset-password", $post + [
            'temporary_password' => self::TEMP, 'confirm_password' => self::TEMP, 'confirm_reset' => '1',
        ]);
    }

    public function testOnlyTheSuperAdminCanReset(): void
    {
        $before = $this->hash();

        $this->reset($this->adminSession)->assertRedirectTo('/admin/dashboard');
        $this->reset($this->memberSession)->assertRedirectTo('/login');
        $this->post("admin/members/{$this->memberId}/reset-password", ['temporary_password' => self::TEMP, 'confirm_password' => self::TEMP, 'confirm_reset' => '1'])
             ->assertRedirectTo('/login');
        // A GET to the action is not a route.
        try {
            $this->withSession($this->superSession)->get("admin/members/{$this->memberId}/reset-password");
            $this->fail('GET must not reach the reset action.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
        }

        $this->assertSame($before, $this->hash());
        $this->seeInDatabase('users', ['user_id' => $this->memberUserId, 'must_change_password' => 0]);
        $this->dontSeeInDatabase('blockchain_ledger', ['event_type' => LedgerService::EVENT_PASSWORD_RESET]);
    }

    public function testButtonIsShownToTheSuperAdminOnly(): void
    {
        $this->withSession($this->superSession)->get('admin/members')->assertSee('resetPasswordModal');
        $this->withSession($this->adminSession)->get('admin/members')->assertDontSee('resetPasswordModal');
    }

    public function testWeakMismatchedOrUnconfirmedPasswordsAreRefused(): void
    {
        $before = $this->hash();

        foreach ([
            ['temporary_password' => 'short1!', 'confirm_password' => 'short1!'],           // too short
            ['temporary_password' => 'alllowercase1!', 'confirm_password' => 'alllowercase1!'],
            ['temporary_password' => 'NoSymbols123', 'confirm_password' => 'NoSymbols123'],
            ['temporary_password' => 'Jdoe#Pass42', 'confirm_password' => 'Jdoe#Pass42'],   // contains the username
            ['confirm_password' => 'Other#Pass42'],                                          // mismatch
            ['confirm_reset' => ''],                                                         // not confirmed
        ] as $post) {
            $this->reset($this->superSession, $post)->assertRedirectTo('/admin/members');
        }

        $this->assertSame($before, $this->hash());
        $this->dontSeeInDatabase('blockchain_ledger', ['event_type' => LedgerService::EVENT_PASSWORD_RESET]);
    }

    public function testAdministratorsAndUnknownMembersCannotBeReset(): void
    {
        $this->withSession($this->superSession)->post('admin/members/999999/reset-password', [
            'temporary_password' => self::TEMP, 'confirm_password' => self::TEMP, 'confirm_reset' => '1',
        ])->assertRedirectTo('/admin/members');

        $this->dontSeeInDatabase('blockchain_ledger', ['event_type' => LedgerService::EVENT_PASSWORD_RESET]);
    }

    public function testResetHashesFlagsLedgersAndAuditsWithoutThePassword(): void
    {
        $this->reset($this->superSession)->assertRedirectTo('/admin/members');

        $user = $this->db->table('users')->where('user_id', $this->memberUserId)->get()->getRowArray();
        $this->assertTrue(password_verify(self::TEMP, $user['password_hash']));
        $this->assertFalse(password_verify('Member@123', $user['password_hash']), 'The old password stops working.');
        $this->assertSame(1, (int) $user['must_change_password']);

        $block = $this->db->table('blockchain_ledger')->where('event_type', LedgerService::EVENT_PASSWORD_RESET)->get()->getRowArray();
        $this->assertNotNull($block);
        $this->assertNull($block['financial_record_id']);
        $this->assertSame($this->superSession['user_id'], (int) $block['event_actor_id']);
        $this->assertSame($this->memberId, (int) $block['event_subject_id']);
        $this->assertSame(64, strlen($block['transaction_hash']));

        $audit = $this->db->table('audit_logs')->where('action', 'PASSWORD_RESET')->get()->getRowArray();
        $this->assertNotNull($audit);
        $this->assertSame('Success', $audit['result']);
        $this->assertSame((string) $this->memberId, $audit['entity_id']);
        $this->assertSame($this->superSession['user_id'], (int) $audit['user_id']);

        // The password and its hash appear in no audit or ledger row.
        foreach ($this->db->table('audit_logs')->get()->getResultArray() as $row) {
            $this->assertStringNotContainsString(self::TEMP, json_encode($row));
            $this->assertStringNotContainsString($user['password_hash'], json_encode($row));
        }
        $this->assertStringNotContainsString(self::TEMP, json_encode($block));

        $this->assertSame('PASS', (new LedgerService())->verifyLedger()['result']);
    }

    public function testChangingAResetEntryBreaksTheChain(): void
    {
        $this->reset($this->superSession);
        $this->db->table('blockchain_ledger')->where('event_type', LedgerService::EVENT_PASSWORD_RESET)
             ->update(['event_actor_id' => $this->adminSession['user_id']]);

        $this->assertSame('FAIL', (new LedgerService())->verifyLedger()['result']);
    }

    public function testOpenMemberSessionEndsAtReset(): void
    {
        $session = $this->memberSession + ['password_mark' => \App\Libraries\AccountAccess::passwordMark($this->hash())];
        $this->withSession($session)->get('member/dashboard')->assertOK();

        $this->reset($this->superSession);

        $this->withSession($session)->get('member/dashboard')->assertRedirectTo('/login');
    }

    public function testMemberMustChangeTheTemporaryPasswordFirst(): void
    {
        $this->reset($this->superSession);

        $this->post('login', ['username' => 'jdoe', 'password' => self::TEMP])->assertRedirectTo('/member/settings');
        $this->assertTrue($_SESSION['must_change_password'] ?? false);

        $session = $this->memberSession + ['must_change_password' => true];
        $this->withSession($session)->get('member/dashboard')->assertRedirectTo('/member/settings');
        $this->withSession($session)->get('member/loans')->assertRedirectTo('/member/settings');
        $this->withSession($session)->post('member/loans/apply', ['loan_amount' => 300])->assertRedirectTo('/member/settings');
        $page = $this->withSession($session)->get('member/settings');
        $page->assertOK();
        $page->assertSee('Your password was reset by the Superadmin');

        $this->withSession($session)->post('member/settings/password', [
            'current_password' => self::TEMP, 'new_password' => 'MyOwn#Pass9', 'confirm_password' => 'MyOwn#Pass9',
        ])->assertRedirectTo('/member/dashboard');

        $this->seeInDatabase('users', ['user_id' => $this->memberUserId, 'must_change_password' => 0]);
        $this->assertArrayNotHasKey('must_change_password', $_SESSION);
        $this->post('login', ['username' => 'jdoe', 'password' => 'MyOwn#Pass9'])->assertRedirectTo('/member/dashboard');
    }

    public function testLoginPageExplainsWhomToContact(): void
    {
        $page = $this->get('login');
        $page->assertOK();
        $page->assertSee('Forgot Password?');
        $page->assertSee('Only the Superadmin can reset member passwords.');
    }
}
