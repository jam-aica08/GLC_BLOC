<?php

use App\Controllers\Admin;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Reports: the CSV export cannot carry spreadsheet formulas, and custom
 * dates must be real calendar dates.
 * Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class ReportExportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

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

        $admin = $this->db->table('users')->where('username', 'admin')->get()->getRowArray();
        $this->adminSession = ['user_id' => (int) $admin['user_id'], 'username' => 'admin', 'role' => 'Admin', 'is_super_admin' => true, 'logged_in' => true];
    }

    public function testCsvTextNeutralisesFormulaStarts(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\t=x", "\r=x"] as $value) {
            $this->assertSame("'" . $value, Admin::csvText($value));
        }
        foreach (['Maria Santos', 'M-0001', 'ACC-2026-001', '', null] as $value) {
            $this->assertSame((string) $value, Admin::csvText($value));
        }
    }

    public function testExportedMemberNameIsNotAFormula(): void
    {
        $member = $this->db->table('members')->get()->getRowArray();
        $this->db->table('members')->where('member_id', $member['member_id'])
            ->update(['first_name' => '=HYPERLINK("https://example.test","Click")', 'last_name' => 'X']);

        // A download keeps its body out of getBody(); send it to read the file.
        $download = $this->withSession($this->adminSession)->get('admin/reports/export?period=all')->response();
        ob_start();
        $download->sendBody();
        $csv = ob_get_clean();

        $this->assertStringContainsString(",\"'=HYPERLINK(\"\"https://example.test\"\",\"\"Click\"\") X\",", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function testImpossibleCustomDatesFallBackToDefaults(): void
    {
        $page = $this->withSession($this->adminSession)
            ->get('admin/reports?period=custom&from=2026-02-31&to=2026-99-99');

        $page->assertOK();
        $page->assertSee('value="' . date('Y-01-01') . '"');
        $page->assertDontSee('2026-02-31');
        $page->assertDontSee('2026-99-99');
    }
}
