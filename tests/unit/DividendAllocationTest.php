<?php

use App\Services\DividendService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Dividend split: Basic weight 1.00, Pioneer 1.02, inside the same pool,
 * in whole centavos that add up to the pool exactly.
 *
 * @internal
 */
final class DividendAllocationTest extends CIUnitTestCase
{
    private function accounts(int $basic, int $pioneer): array
    {
        $accounts = [];
        $id       = 1;
        for ($i = 0; $i < $basic; $i++) {
            $accounts[] = ['account_id' => $id++, 'account_type' => 'Basic'];
        }
        for ($i = 0; $i < $pioneer; $i++) {
            $accounts[] = ['account_id' => $id++, 'account_type' => 'Pioneer'];
        }

        return $accounts;
    }

    public function testExampleOneHundredTwentyThousand(): void
    {
        $shares = DividendService::allocate(12_000_000, $this->accounts(30, 10));

        $this->assertSame(12_000_000, array_sum($shares));
        foreach (array_slice($shares, 0, 30, true) as $centavos) {
            $this->assertContains($centavos, [298_507, 298_508]); // exact 2,985.074627
        }
        foreach (array_slice($shares, 30, 10, true) as $centavos) {
            $this->assertContains($centavos, [304_477, 304_478]); // exact 3,044.776119
        }
    }

    public function testExampleOneHundredTwoBasicNoPioneer(): void
    {
        $this->assertSame([1 => 5_000, 2 => 5_000], DividendService::allocate(10_000, $this->accounts(2, 0)));
    }

    public function testPioneerGetsTwoPercentMoreFromTheSamePool(): void
    {
        $shares = DividendService::allocate(10_200 + 10_000, $this->accounts(1, 1));

        $this->assertSame([1 => 10_000, 2 => 10_200], $shares);
    }

    public function testLeftoverCentavosGoToLargestRemaindersThenLowestId(): void
    {
        // 100 centavos over 3 equal accounts: 33.33… each, one centavo left for account 1.
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], DividendService::allocate(100, $this->accounts(3, 0)));
    }

    public function testTotalsAlwaysEqualThePoolAndStayWithinOneCentavo(): void
    {
        mt_srand(2026);
        for ($t = 0; $t < 500; $t++) {
            $basic    = mt_rand(0, 60);
            $pioneer  = mt_rand($basic === 0 ? 1 : 0, 30);
            $pool     = mt_rand(1, 50_000_000);
            $accounts = $this->accounts($basic, $pioneer);
            $shares   = DividendService::allocate($pool, $accounts);
            $weight   = 100 * $basic + 102 * $pioneer;

            $this->assertSame($pool, array_sum($shares));
            foreach ($accounts as $a) {
                $exact = $pool * ($a['account_type'] === 'Pioneer' ? 102 : 100) / $weight;
                $this->assertLessThan(1.0, abs($shares[$a['account_id']] - $exact));
            }
        }
    }

    public function testNothingToShare(): void
    {
        $this->assertSame([], DividendService::allocate(0, $this->accounts(3, 1)));
        $this->assertSame([], DividendService::allocate(50_000, []));
    }
}
