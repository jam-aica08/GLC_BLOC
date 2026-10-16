<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Wipes cooperative data so the system can be tested from a clean start.
 * Administrator logins, roles and the schema are never touched.
 */
class DataReset
{
    /** Transaction tables, children first so foreign keys never block a delete. */
    private const TRANSACTION_TABLES = [
        'loan_payment_installments',
        'loan_payments',
        'loan_installments',
        'loans',
        'contributions',
        'dividends',
        'blockchain_ledger',
        'financial_records',
        'analytics_snapshots',
    ];

    /**
     * @param bool $includeMembers Also delete members, their accounts and their logins.
     *
     * @return array<string, int>|string Rows deleted per table, or an error message.
     */
    public static function run(bool $includeMembers, ?BaseConnection $db = null): array|string
    {
        $db ??= db_connect();
        $deleted = [];

        $db->transBegin();

        try {
            foreach (self::TRANSACTION_TABLES as $table) {
                $deleted[$table] = self::wipe($db, $table);
            }

            if ($includeMembers) {
                $memberUserIds = array_column(
                    $db->table('users u')->select('u.user_id')
                       ->join('roles r', 'r.role_id = u.role_id')
                       ->where('r.role_name', 'Member')
                       ->get()->getResultArray(),
                    'user_id'
                );

                $deleted['cooperative_accounts'] = self::wipe($db, 'cooperative_accounts');
                $deleted['members']              = self::wipe($db, 'members');

                if ($memberUserIds !== []) {
                    $db->table('users')->whereIn('user_id', $memberUserIds)->delete();
                }
                $deleted['users (members)'] = count($memberUserIds);
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('The database refused one of the deletes.');
            }

            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            return $e->getMessage();
        }

        return $deleted;
    }

    private static function wipe(BaseConnection $db, string $table): int
    {
        if (! $db->tableExists($table)) {
            return 0;
        }

        $count = $db->table($table)->countAllResults();
        $db->table($table)->where('1 = 1', null, false)->delete();

        return $count;
    }
}
