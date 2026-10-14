<?php

namespace App\Libraries;

/**
 * Lists and applies database migrations that have not run yet, so an admin
 * can bring a hosted database up to date from the Settings page.
 */
class DatabaseUpdates
{
    /**
     * @return list<string> Names of migrations that have not been applied.
     */
    public static function pending(): array
    {
        try {
            $runner  = service('migrations');
            $applied = [];
            foreach ($runner->getHistory() as $row) {
                $applied[$row->version . '|' . $row->class] = true;
            }

            $pending = [];
            foreach ($runner->findMigrations() as $migration) {
                if (! isset($applied[$migration->version . '|' . $migration->class])) {
                    $pending[] = $migration->name;
                }
            }

            return $pending;
        } catch (\Throwable $e) {
            log_message('error', 'DatabaseUpdates::pending failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Apply every pending migration. Returns null on success or an error message.
     */
    public static function apply(): ?string
    {
        try {
            service('migrations')->latest();
            return null;
        } catch (\Throwable $e) {
            log_message('error', 'DatabaseUpdates::apply failed: ' . $e->getMessage());
            return $e->getMessage();
        }
    }
}
