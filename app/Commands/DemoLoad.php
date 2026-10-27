<?php

namespace App\Commands;

use App\Libraries\DemoData;
use App\Libraries\DemoLoader;
use App\Libraries\ImportWorkbook;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Loads made-up demo records through the same importer clients use,
 * distributes dividends for each finished year, then sets up the showcase
 * situations (withdrawals, deactivations, offsets…) so every screen has data.
 * See DemoLoader.
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

        $result = DemoLoader::run((int) $admin['user_id'], $members, $fresh);
        if (is_string($result)) {
            CLI::error($result . ($fresh ? '' : ' Use --fresh to replace earlier demo data.'));
            return EXIT_ERROR;
        }

        foreach ($result['counts'] as $sheet => $n) {
            CLI::write(str_pad($sheet, 16) . $n);
        }
        CLI::write(str_pad('Ledger entries', 16) . $result['ledger']);
        foreach ($result['dividends'] as $line) {
            CLI::write($line);
        }
        foreach ($result['showcase'] as $line) {
            CLI::write('  ' . $line, 'cyan');
        }

        CLI::write(sprintf('Done in %.1fs. Demo members sign in with their username and the password %s.', $result['seconds'], DemoData::PASSWORD), 'green');
    }
}
