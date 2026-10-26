<?php

use App\Services\LedgerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The hash-chained ledger: an untouched chain passes, and changing or
 * deleting a record is reported at that entry with every later entry
 * flagged as Tampered. Needs the MySQL test database, like AuthSecurityTest.
 *
 * @internal
 */
final class LedgerVerificationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = \App\Database\Seeds\GlcBlocSeeder::class;

    /** @var list<int> financial_record_id of the entries this test appended, in order */
    private array $records = [];

    protected function setUp(): void
    {
        if (config('Database')->tests['DBDriver'] !== 'MySQLi') {
            if (getenv('CI')) {
                $this->fail('CI must provide the MySQL test database (database_tests_* variables).');
            }
            $this->markTestSkipped('Needs the MySQL test database (database.tests.*).');
        }
        parent::setUp();

        $accountId = (int) $this->db->table('cooperative_accounts')->get()->getRowArray()['account_id'];
        $ledger    = new LedgerService();
        for ($i = 1; $i <= 6; $i++) {
            $this->db->table('financial_records')->insert([
                'account_id' => $accountId, 'record_type' => 'Contribution', 'transaction_date' => '2026-0' . $i . '-15',
                'amount' => 300 * $i, 'approval_status' => 'Approved', 'processed_by' => 1,
            ]);
            $id = (int) $this->db->insertID();
            $ledger->createEntry($id, $this->db->table('financial_records')->where('financial_record_id', $id)->get()->getRowArray());
            $this->records[] = $id;
        }
    }

    private function statusOf(int $recordId): string
    {
        return $this->db->table('blockchain_ledger')->where('financial_record_id', $recordId)->get()->getRowArray()['verification_status'];
    }

    public function testUntouchedChainPasses(): void
    {
        $result = (new LedgerService())->verifyLedger();

        $this->assertSame('PASS', $result['result']);
        $this->assertSame(0, $result['tampered']);
        $this->assertSame($result['checked'], $result['valid']);
    }

    public function testChangedAmountIsReportedAndLaterEntriesFlagged(): void
    {
        $this->db->table('financial_records')->where('financial_record_id', $this->records[3])->update(['amount' => 1]);
        $before = $this->db->table('blockchain_ledger')->countAllResults();

        $result = (new LedgerService())->verifyLedger();

        $this->assertSame('FAIL', $result['result']);
        $this->assertSame('hash_mismatch', $result['first_failure']['failure_type']);
        $this->assertSame(3, $result['tampered'], 'The changed entry and the two after it.');
        $this->assertSame($before, $result['checked']);
        foreach ([0, 1, 2] as $i) {
            $this->assertSame('Valid', $this->statusOf($this->records[$i]));
        }
        foreach ([3, 4, 5] as $i) {
            $this->assertSame('Tampered', $this->statusOf($this->records[$i]));
        }
    }

    public function testRecordInTheLedgerCannotBeDeleted(): void
    {
        // The foreign key from blockchain_ledger refuses it outright.
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $this->db->table('financial_records')->where('financial_record_id', $this->records[1])->delete();
    }

    public function testRestoringTheRecordClearsTheFlags(): void
    {
        $this->db->table('financial_records')->where('financial_record_id', $this->records[2])->update(['amount' => 1]);
        (new LedgerService())->verifyLedger();
        $this->db->table('financial_records')->where('financial_record_id', $this->records[2])->update(['amount' => 900]);

        $result = (new LedgerService())->verifyLedger();

        $this->assertSame('PASS', $result['result']);
        $this->assertSame('Valid', $this->statusOf($this->records[5]));
    }
}
