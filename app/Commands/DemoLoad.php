<?php

namespace App\Commands;

use App\Libraries\DataReset;
use App\Libraries\DemoData;
use App\Libraries\ImportWorkbook;
use App\Services\DataImportService;
use App\Services\DividendService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Loads made-up demo records through the same importer clients use, then
 * distributes dividends for each finished year so every screen has data.
 */
class DemoLoad extends BaseCommand
{
    protected $group       = 'GLC-BLOC';
    protected $name        = 'demo:load';
    protected $description = 'Load dummy demo data (members, contributions, loans, payments, dividends).';
    protected $usage       = 'demo:load [--fresh] [--members 40] [--file sample.xlsx] [--yes]';
    protected $options     = [
        '--members' => 'How many members to create (default 40).',
        '--fresh'   => 'First delete every member and transaction (keeps administrator logins).',
        '--file'    => 'Only write the demo data to this .xlsx file (the import sample); nothing is saved.',
        '--yes'     => 'Do not ask for confirmation.',
    ];

    public function run(array $params)
    {
        $members = max(1, (int) (CLI::getOption('members') ?: 40));
        $data    = DemoData::build($members);

        if ($file = CLI::getOption('file')) {
            ImportWorkbook::write($file, $data);
            CLI::write("Wrote {$file}", 'green');
            return;
        }

        $db    = db_connect();
        $admin = $db->table('users u')->select('u.user_id, u.username')->join('roles r', 'r.role_id = u.role_id')
                    ->where('r.role_name', 'Admin')->orderBy('u.is_super_admin', 'DESC')->orderBy('u.user_id', 'ASC')->get()->getRowArray();
        if (! $admin) {
            CLI::error('Create an administrator first (php spark db:seed GlcBlocSeeder).');
            return EXIT_ERROR;
        }

        $target = $db->getDatabase() . ' on ' . $db->hostname;
        $fresh  = (bool) CLI::getOption('fresh');
        CLI::write("Database: {$target}");
        CLI::write(($fresh ? 'Delete every member and transaction, then load ' : 'Load ') . "{$members} demo members as {$admin['username']}.");
        if (! CLI::getOption('yes') && CLI::prompt('Continue?', ['n', 'y']) !== 'y') {
            return;
        }

        if ($fresh) {
            $result = DataReset::run(true, $db);
            if (is_string($result)) {
                CLI::error('Reset failed: ' . $result);
                return EXIT_ERROR;
            }
            CLI::write('Cleared existing members and transactions.', 'yellow');
        }

        $service = new DataImportService();
        $check   = $service->validate($data);
        if ($check['errors'] !== []) {
            foreach (array_slice($check['errors'], 0, 15) as $e) {
                CLI::error("{$e['sheet']} row {$e['row']} {$e['column']}: {$e['message']}");
            }
            CLI::error(count($check['errors']) . ' problem(s); nothing was loaded. Use --fresh to replace earlier demo data.');
            return EXIT_ERROR;
        }

        $started = microtime(true);
        $result  = $service->import($check['plan'], (int) $admin['user_id']);
        foreach ($result['counts'] as $sheet => $n) {
            CLI::write(str_pad($sheet, 16) . $n);
        }
        CLI::write(str_pad('Ledger entries', 16) . $result['ledger']);

        // Dividends for each finished year that earned loan interest.
        $dividends = new DividendService($db);
        for ($year = 2022; $year < (int) date('Y'); $year++) {
            $plan = $dividends->plan($year);
            if ($plan['errors'] !== [] || $plan['existing'] || $plan['legacy_paid']) {
                continue;
            }
            [$id, $error] = $dividends->create($year, "{$year}-12-20", (int) $admin['user_id'], $plan['profit_pool'], count($plan['accounts']));
            if ($id) {
                $dividends->approve((int) $id, (int) $admin['user_id']);
                CLI::write("Dividends {$year}: PHP " . number_format($plan['profit_pool'], 2) . ' to ' . count($plan['accounts']) . ' accounts');
            } elseif ($error) {
                CLI::write("Dividends {$year}: {$error}", 'yellow');
            }
        }

        CLI::write(sprintf('Done in %.1fs. Demo members sign in with their username and the password %s.', microtime(true) - $started, DemoData::PASSWORD), 'green');
    }
}
