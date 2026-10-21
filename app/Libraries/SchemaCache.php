<?php

namespace App\Libraries;

use Throwable;

/**
 * Remembers schema checks that passed ("this table exists"), so pages do not
 * ask the database about its own structure on every request. Only a passing
 * check is remembered: tables are added by updates, never removed by the app,
 * so a missing table is checked again next time. The key includes the number
 * of migration files, so a deployment with a new migration starts fresh.
 */
class SchemaCache
{
    private const TTL = 3600;

    /** @var array<string, true> */
    private static array $passed = [];

    /** @param callable(): bool $check */
    public static function passes(string $name, callable $check): bool
    {
        $key = self::key($name);
        if (isset(self::$passed[$key])) {
            return true;
        }
        try {
            if (cache($key)) {
                return self::$passed[$key] = true;
            }
        } catch (Throwable) {
            // No cache: just run the check.
        }
        if (! $check()) {
            return false;
        }
        self::$passed[$key] = true;
        try {
            cache()->save($key, 1, self::TTL);
        } catch (Throwable) {
        }

        return true;
    }

    private static function key(string $name): string
    {
        static $migrations = null;
        $migrations ??= count(glob(APPPATH . 'Database/Migrations/*.php') ?: []);

        return 'schema_' . $migrations . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }
}
