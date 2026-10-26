<?php

use App\Filters\AuthFilter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * A sign-in ends when the browser closes (browser-session cookie) and after
 * AuthFilter::IDLE_MINUTES without a request. Needs the MySQL test database,
 * like AuthSecurityTest.
 *
 * @internal
 */
final class SessionTimeoutTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

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

        $user        = $this->db->table('users')->where('username', 'admin')->get()->getRowArray();
        $this->admin = ['user_id' => (int) $user['user_id'], 'username' => 'admin', 'role' => 'Admin', 'is_super_admin' => true, 'logged_in' => true];
    }

    public function testCookieEndsWithTheBrowser(): void
    {
        $this->assertSame(0, config('Session')->expiration, 'A non-zero expiration gives the cookie a fixed expiry date that survives closing the browser.');
    }

    public function testRecentActivityKeepsTheSignIn(): void
    {
        $this->withSession($this->admin + ['last_activity' => time() - 60])
            ->get('admin/dashboard')
            ->assertOK();
    }

    public function testIdleSignInIsSentToLogin(): void
    {
        $result = $this->withSession($this->admin + ['last_activity' => time() - (AuthFilter::IDLE_MINUTES * 60 + 5)])
            ->get('admin/dashboard');

        $result->assertRedirectTo('/login');
        $this->assertStringContainsString('minutes without activity', (string) session()->getFlashdata('error'));
        $this->assertNull(session()->get('user_id'));
    }

    public function testSessionFromBeforeTheChangeIsNotThrownOut(): void
    {
        // Signed in before last_activity existed: allowed, and the clock starts now.
        $this->withSession($this->admin)->get('admin/dashboard')->assertOK();

        $this->assertEqualsWithDelta(time(), session()->get('last_activity'), 5);
    }

    public function testBackgroundRequestOfAnEndedSignInGets401(): void
    {
        $result = $this->withSession($this->admin + ['last_activity' => time() - (AuthFilter::IDLE_MINUTES * 60 + 5)])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get('admin/analytics/activity?range=24h');

        $result->assertStatus(401);
        $this->assertTrue(json_decode($result->getJSON(), true)['signedOut']);
    }

    public function testPasswordChangeEndsOtherSignIns(): void
    {
        $hash  = $this->db->table('users')->where('user_id', $this->admin['user_id'])->get()->getRow('password_hash');
        $other = $this->admin + ['last_activity' => time(), 'password_mark' => \App\Libraries\AccountAccess::passwordMark($hash)];
        $this->withSession($other)->get('admin/dashboard')->assertOK();

        // The password is changed (from another device).
        $this->db->table('users')->where('user_id', $this->admin['user_id'])->update(['password_hash' => password_hash('Another@789', PASSWORD_DEFAULT)]);

        $result = $this->withSession($other)->get('admin/dashboard');
        $result->assertRedirectTo('/login');
        $this->assertStringContainsString('password was changed', (string) session()->getFlashdata('error'));
    }

    public function testTheSessionThatChangesThePasswordStaysSignedIn(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'Admin@123']);
        $this->withSession(session()->get())
            ->post('admin/settings/password', ['current_password' => 'Admin@123', 'new_password' => 'Another@789', 'confirm_password' => 'Another@789'])
            ->assertRedirectTo('/admin/settings');

        $this->withSession(session()->get())->get('admin/dashboard')->assertOK();
    }

    public function testSignInStartsTheIdleClock(): void
    {
        $this->post('login', ['username' => 'admin', 'password' => 'Admin@123'])->assertRedirectTo('/admin/dashboard');

        $this->assertEqualsWithDelta(time(), session()->get('last_activity'), 5);
    }
}
