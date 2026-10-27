<?php

namespace App\Libraries;

use App\Services\DataImportService;
use App\Services\DividendService;

/**
 * Replaces every member and transaction with made-up demo data: the members,
 * accounts, contributions, loans and payments of DemoData (through the same
 * importer clients use), dividends for each finished year, then the
 * DemoShowcase situations. Administrator logins are kept. Used by
 * `php spark demo:load --fresh` and by the super admin's "Load Demo Data".
 */
class DemoLoader
{
    /**
     * @return array{counts: array<string, int>, ledger: int, dividends: list<string>, showcase: list<string>, seconds: float}|string
     *         what was loaded, or an error message (then nothing was loaded)
     */
    public static function run(int $adminId, int $members = 40, bool $fresh = true): array|string
    {
        $started = microtime(true);
        $data    = DemoData::build($members);
        $service = new DataImportService();

        if ($fresh) {
            $reset = DataReset::run(true);
            if (is_string($reset)) {
                return 'Reset failed, nothing was changed: ' . $reset;
            }
        }

        $check = $service->validate($data);
        if ($check['errors'] !== []) {
            $first = $check['errors'][0];
            return count($check['errors']) . " problem(s) in the demo data, nothing was loaded (first: {$first['sheet']} row {$first['row']} {$first['column']}: {$first['message']}).";
        }
        $result = $service->import($check['plan'], $adminId);

        // Dividends for each finished year that earned loan interest.
        $lines     = [];
        $dividends = new DividendService();
        for ($year = 2022; $year < (int) date('Y'); $year++) {
            $plan = $dividends->plan($year);
            if ($plan['errors'] !== [] || $plan['existing'] || $plan['legacy_paid']) {
                continue;
            }
            [$id, $error] = $dividends->create($year, "{$year}-12-20", $adminId, $plan['profit_pool'], count($plan['accounts']));
            if ($id) {
                $dividends->approve((int) $id, $adminId);
                $lines[] = "Dividends {$year}: PHP " . number_format($plan['total_distributed'], 2) . ' to ' . count($plan['accounts'])
                    . ' account(s) with PHP 3,600 for the year (administration 3%: PHP ' . number_format($plan['admin_share'], 2) . ')';
            } elseif ($error) {
                $lines[] = "Dividends {$year}: {$error}";
            }
        }

        $showcase = (new DemoShowcase($adminId))->apply();

        return [
            'counts'    => $result['counts'],
            'ledger'    => (int) $result['ledger'],
            'dividends' => $lines,
            'showcase'  => $showcase,
            'seconds'   => round(microtime(true) - $started, 1),
        ];
    }
}
