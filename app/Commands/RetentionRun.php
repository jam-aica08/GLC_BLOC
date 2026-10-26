<?php

namespace App\Commands;

use App\Services\RetentionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Runs the daily data retention review from the command line (the hosted
 * site runs it through Vercel Cron instead; see vercel.json).
 */
class RetentionRun extends BaseCommand
{
    protected $group       = 'GLC-BLOC';
    protected $name        = 'retention:run';
    protected $description = 'Run the data retention review: delete expired records, flag and deactivate inactive members.';
    protected $usage       = 'retention:run';

    public function run(array $params)
    {
        $summary = (new RetentionService())->run();

        foreach ($summary as $what => $count) {
            CLI::write(str_pad((string) $count, 6, ' ', STR_PAD_LEFT) . "  {$what}");
        }
        CLI::write(RetentionService::describe($summary), 'green');
    }
}
