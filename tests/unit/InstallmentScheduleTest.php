<?php

use App\Services\LoanService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Due dates of a new loan's schedule: one per calendar month after the
 * release date, on the same day of the month, or the month's last day when
 * that month is shorter (a loan released on 31 May is due 30 June, 31 July).
 *
 * @internal
 */
final class InstallmentScheduleTest extends CIUnitTestCase
{
    private function dueDates(string $releaseDate, int $term): array
    {
        return array_column((new LoanService())->calculateInstallments([
            'loan_amount'      => 1200,
            'interest_rate'    => 5,
            'loan_term_months' => $term,
            'release_date'     => $releaseDate,
        ]), 'due_date');
    }

    public function testMidMonthReleaseKeepsTheDay(): void
    {
        $this->assertSame(['2026-11-15', '2026-12-15', '2027-01-15'], $this->dueDates('2026-10-15', 3));
    }

    public function testEndOfMonthReleaseNeverSkipsAMonth(): void
    {
        $this->assertSame(
            ['2024-06-30', '2024-07-31', '2024-08-31', '2024-09-30', '2024-10-31', '2024-11-30'],
            $this->dueDates('2024-05-31', 6)
        );
    }

    public function testJanuaryThirtiethFallsOnTheLastDayOfFebruary(): void
    {
        $this->assertSame(['2026-02-28', '2026-03-30'], $this->dueDates('2026-01-30', 2));
        $this->assertSame(['2028-02-29', '2028-03-30'], $this->dueDates('2028-01-30', 2), 'Leap year');
    }

    public function testEveryMonthHasExactlyOneDueDate(): void
    {
        foreach (['2026-01-29', '2026-01-31', '2026-03-31', '2026-08-31', '2026-12-31'] as $release) {
            $months = array_map(fn($d) => substr($d, 0, 7), $this->dueDates($release, 24));
            $this->assertSame(array_values(array_unique($months)), $months, "Two due dates in one month for {$release}");
            $this->assertCount(24, $months);

            $first = new DateTime(substr($release, 0, 7) . '-01');
            $this->assertSame($first->modify('+1 month')->format('Y-m'), $months[0], "First due month for {$release}");
        }
    }

    public function testAmountsStillAddUp(): void
    {
        $rows = (new LoanService())->calculateInstallments([
            'loan_amount' => 1000, 'interest_rate' => 2, 'loan_term_months' => 3, 'release_date' => '2026-05-31',
        ]);

        $this->assertSame(1000.0, round(array_sum(array_column($rows, 'principal_amount')), 2));
        $this->assertSame(20.0, (float) $rows[0]['interest_amount']);
    }
}
