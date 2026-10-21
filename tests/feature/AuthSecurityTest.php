<?php

use App\Libraries\LoginThrottle;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Sign-in and access rules (audit defects D-01, D-02, D-03).
 *
 * Needs a MySQL test database (the migrations are MySQL-specific): set the
 * database.tests.* values, as phpunit.xml / the CI workflow do. Skipped on
 * the default in-memory SQLite group.
 *
 * @internal
 */
final class AuthSecurityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $migrateOnce = false;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;


    protected function setUp(): void
    {
        if (config('Database')->tests['DBDriver'] !== 'MySQLi') {
            $this->markTestSkipped('Needs the MySQL test database (database.tests.*).');
        }
        parent::setUp();

        // CSRF regenerates its token after every POST and has its own HTTP test;
        // these tests are about sign-in and access rules, so it is switched off.
        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_filter($filters->globals['before'], fn($f) => $f !== 'csrf'));
    }

    private function login(string $username, string $password)
    {
        return $this->post('login', ['username' => $username, 'password' => $password]);
    }

    private function memberSession(): array
    {
        $row = $this->db->table('users u')->select('u.user_id, m.member_id')
            ->join('members m', 'm.user_id = u.user_id')->where('u.username', 'jdoe')->get()->getRowArray();

        return ['user_id' => (int) $row['user_id'], 'username' => 'jdoe', 'role' => 'Member', 'member_id' => (int) $row['member_id'], 'logged_in' => true];
    }

    public function testValidMemberCanLogIn(): void
    {
        $this->login('jdoe', 'Member@123')->assertRedirectTo('/member/dashboard');
    }

    public function testDeactivatedMemberCannotLogIn(): void
    {
        $session = $this->memberSession();
        $this->withSession(['user_id' => 1, 'username' => 'admin', 'role' => 'Admin', 'logged_in' => true])
            ->post('admin/members/deactivate/' . $session['member_id']);

        $this->seeInDatabase('users', ['username' => 'jdoe', 'account_status' => 'Inactive']);
        $this->login('jdoe', 'Member@123')->assertRedirectTo('/login');
    }

    public function testOpenSessionEndsWhenMembershipIsDeactivated(): void
    {
        $session = $this->memberSession();
        $this->withSession($session)->get('member/dashboard')->assertOK();

        // Membership switched off by any means (older data, direct edits): the next request is refused.
        $this->db->table('members')->where('member_id', $session['member_id'])->update(['membership_status' => 'Inactive']);
        $this->withSession($session)->get('member/dashboard')->assertRedirectTo('/login');
    }

    public function testReactivatedMemberCanLogInAgain(): void
    {
        $session = $this->memberSession();
        $admin   = ['user_id' => 1, 'username' => 'admin', 'role' => 'Admin', 'logged_in' => true];
        $this->withSession($admin)->post('admin/members/deactivate/' . $session['member_id']);
        $this->withSession($admin)->post('admin/members/reactivate/' . $session['member_id']);

        $this->login('jdoe', 'Member@123')->assertRedirectTo('/member/dashboard');
    }

    public function testLoginIsRefusedAfterTooManyFailures(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_PER_USERNAME; $i++) {
            $this->login('jdoe', 'wrong-' . $i)->assertRedirectTo('/login');
        }

        $this->assertGreaterThan(0, LoginThrottle::minutesLeft('jdoe', '0.0.0.0'));
        $this->login('jdoe', 'Member@123')->assertRedirectTo('/login');
        $this->assertSame(0, LoginThrottle::minutesLeft('admin', '0.0.0.0'), 'Other usernames are not affected.');
    }

    public function testSuccessfulLoginClearsEarlierFailures(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_PER_USERNAME - 1; $i++) {
            $this->login('jdoe', 'wrong-' . $i);
        }
        $this->login('jdoe', 'Member@123')->assertRedirectTo('/member/dashboard');
        $this->login('jdoe', 'wrong-again');

        $this->assertSame(0, LoginThrottle::minutesLeft('jdoe', '0.0.0.0'));
    }

    public function testSecurityHeadersAreSent(): void
    {
        $result = $this->get('login');
        $result->assertOK();
        $result->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'self'", $result->response()->getHeaderLine('Content-Security-Policy'));
    }
}
