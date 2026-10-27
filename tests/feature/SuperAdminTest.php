<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Adding admins, resetting data and importing are for the super
 * administrator only. Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class SuperAdminTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    private array $superSession;
    private array $adminSession;

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

        // A second, ordinary administrator.
        $this->db->table('users')->insert([
            'role_id'        => $super['role_id'],
            'username'       => 'admin2',
            'email'          => 'admin2@example.com',
            'password_hash'  => password_hash('Admin2@123', PASSWORD_DEFAULT),
            'account_status' => 'Active',
            'is_super_admin' => 0,
        ]);
        $this->adminSession = ['user_id' => (int) $this->db->insertID(), 'username' => 'admin2', 'role' => 'Admin', 'is_super_admin' => false, 'logged_in' => true];
    }

    public function testOrdinaryAdminCannotAddAdministrators(): void
    {
        $this->withSession($this->adminSession)
            ->post('admin/settings/admins', ['username' => 'sneaky', 'email' => 'sneaky@example.com', 'password' => 'Sneaky@123']);

        $this->dontSeeInDatabase('users', ['username' => 'sneaky']);
    }

    public function testOrdinaryAdminCannotResetData(): void
    {
        $members = $this->db->table('members')->countAllResults();
        $this->withSession($this->adminSession)
            ->post('admin/settings/reset', ['scope' => 'all', 'confirm' => 'RESET', 'password' => 'Admin2@123']);

        $this->assertSame($members, $this->db->table('members')->countAllResults());
    }

    public function testOrdinaryAdminCannotOpenOrUseImport(): void
    {
        foreach (['get' => ['admin/import', 'admin/import/template', 'admin/import/sample'], 'post' => ['admin/import']] as $method => $paths) {
            foreach ($paths as $path) {
                $this->withSession($this->adminSession)->{$method}($path)->assertRedirectTo('/admin/dashboard');
            }
        }
    }

    public function testSuperAdminCanImportAndAddAdministrators(): void
    {
        $this->withSession($this->superSession)->get('admin/import')->assertOK();
        // A download streams its file, so check the status and type rather than the body.
        $template = $this->withSession($this->superSession)->get('admin/import/template');
        $template->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml', $template->response()->getHeaderLine('Content-Type'));

        $this->withSession($this->superSession)
            ->post('admin/settings/admins', ['username' => 'admin3', 'email' => 'admin3@example.com', 'password' => 'Admin3@123']);
        $this->seeInDatabase('users', ['username' => 'admin3', 'is_super_admin' => 0]);
    }

    public function testOnlyTheSuperAdminCanLoadDemoData(): void
    {
        $members = $this->db->table('members')->countAllResults();

        $this->withSession($this->adminSession)->post('admin/settings/demo', ['confirm' => 'DEMO', 'password' => 'Admin2@123']);
        $this->withSession($this->superSession)->post('admin/settings/demo', ['confirm' => 'demo', 'password' => 'Admin@123']);
        $this->withSession($this->superSession)->post('admin/settings/demo', ['confirm' => 'DEMO', 'password' => 'wrong']);

        $this->assertSame($members, $this->db->table('members')->countAllResults(), 'Refused: ordinary admin, wrong confirmation, wrong password.');
    }

    public function testLoadingDemoDataReplacesMembersAndAddsTheShowcase(): void
    {
        $this->withSession($this->superSession)->post('admin/settings/demo', ['confirm' => 'DEMO', 'password' => 'Admin@123'])
             ->assertRedirectTo('/admin/settings');

        $this->assertSame(40, $this->db->table('members')->countAllResults());
        $this->dontSeeInDatabase('users', ['username' => 'jdoe']);                 // the old members are gone
        $this->seeInDatabase('users', ['username' => 'admin', 'is_super_admin' => 1]);   // admins are kept
        $this->seeInDatabase('withdrawals', ['status' => 'Pending']);
        $this->seeInDatabase('withdrawals', ['status' => 'Approved']);
        $this->seeInDatabase('cooperative_accounts', ['account_status' => 'Inactive']);
        $this->seeInDatabase('financial_records', ['record_type' => 'Loan Offset', 'approval_status' => 'Approved']);
        $this->seeInDatabase('account_requests', ['request_type' => 'Deactivate', 'status' => 'Pending']);
        $this->assertGreaterThan(0, $this->db->table('cooperative_accounts')->where('reactivation_from IS NOT NULL')->countAllResults());
        $this->seeInDatabase('audit_logs', ['action' => 'Loaded demo data', 'result' => 'Success']);
    }

    public function testSettingsPageShowsTheToolsToTheSuperAdminOnly(): void
    {
        $ordinary = $this->withSession($this->adminSession)->get('admin/settings');
        $ordinary->assertDontSee('Add Administrator');
        $ordinary->assertDontSee('Reset Data');
        $ordinary->assertDontSee('Load Demo Data');

        $super = $this->withSession($this->superSession)->get('admin/settings');
        $super->assertSee('Add Administrator');
        $super->assertSee('Reset Data');
        $super->assertSee('Load Demo Data');
    }
}
