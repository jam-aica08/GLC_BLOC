<?php

namespace App\Services;

use App\Models\CooperativeAccountModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Yearly dividends.
 *
 * Profit pool: interest on loans approved in the year (Active or Paid loans).
 * The pool is shared by every Active account; a Basic account weighs 1.00 and
 * a Pioneer account 1.02, so a Pioneer share is 2% more than a Basic share and
 * the bonus comes out of the same pool:
 *
 *   totalWeight  = basicCount + pioneerCount × 1.02
 *   basicShare   = profitPool / totalWeight
 *   pioneerShare = basicShare × 1.02
 *
 * Money is handled in whole centavos with integer weights (Basic 100,
 * Pioneer 102). Each account gets the floor of its exact share, and the
 * centavos left over go one each to the accounts with the largest remainders
 * (ties: lowest account ID), so the payouts add up to the pool exactly.
 *
 * A distribution is a batch (dividend_distributions) that an administrator
 * approves, like other financial records. Only the approval marks the
 * dividends Distributed and adds them to the ledger.
 */
class DividendService
{
    /** Integer weights: 100 = 1.00 (Basic), 102 = 1.02 (Pioneer). */
    private const WEIGHT = ['Basic' => 100, 'Pioneer' => 102];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /** False until "Apply Database Updates" has added the batch table. */
    public function available(): bool
    {
        return \App\Libraries\SchemaCache::passes('dividend_distributions', fn () => $this->db->tableExists('dividend_distributions')
            && $this->db->fieldExists('distribution_id', 'dividends'));
    }

    public static function validYear(int $year): bool
    {
        return $year >= 2000 && $year <= (int) date('Y');
    }

    /** Interest on loans approved in $year, in centavos. */
    public function profitPoolCentavos(int $year): int
    {
        $row = $this->db->table('loans')
            ->select('COALESCE(SUM(interest_amount), 0) AS pool', false)
            ->whereIn('loan_status', ['Active', 'Paid'])
            ->where('approval_date >=', "{$year}-01-01")
            ->where('approval_date <=', "{$year}-12-31")
            ->get()->getRowArray();

        return (int) round((float) $row['pool'] * 100);
    }

    /**
     * Split $poolCentavos over $accounts (each with account_id, account_type).
     *
     * @return array<int, int> account_id => centavos
     */
    public static function allocate(int $poolCentavos, array $accounts): array
    {
        $totalWeight = 0;
        foreach ($accounts as $a) {
            $totalWeight += self::WEIGHT[$a['account_type']] ?? self::WEIGHT['Basic'];
        }
        if ($poolCentavos <= 0 || $totalWeight === 0) {
            return [];
        }

        $shares = [];
        $order  = [];
        $given  = 0;
        foreach ($accounts as $a) {
            $id        = (int) $a['account_id'];
            $numerator = $poolCentavos * (self::WEIGHT[$a['account_type']] ?? self::WEIGHT['Basic']);
            $shares[$id] = intdiv($numerator, $totalWeight);
            $order[]     = [$numerator % $totalWeight, $id];
            $given      += $shares[$id];
        }

        // Hand out the leftover centavos: largest remainder first, then lowest account ID.
        usort($order, fn($x, $y) => [$y[0], $x[1]] <=> [$x[0], $y[1]]);
        for ($i = 0, $left = $poolCentavos - $given; $left > 0; $i++, $left--) {
            $shares[$order[$i][1]]++;
        }

        return $shares;
    }

    /**
     * Preview for a year from current data. Nothing is saved.
     */
    public function plan(int $year): array
    {
        model(CooperativeAccountModel::class)->syncStatuses();

        // Only accounts that were open by the end of that year share its profit
        // (matters when an earlier year is distributed after imports).
        $accounts = $this->db->table('cooperative_accounts')
            ->select('account_id, account_number, account_type')
            ->where('account_status', 'Active')
            ->where('account_start_date <=', "{$year}-12-31")
            ->orderBy('account_id', 'ASC')
            ->get()->getResultArray();

        $pioneers    = count(array_filter($accounts, fn($a) => $a['account_type'] === 'Pioneer'));
        $basics      = count($accounts) - $pioneers;
        $poolC       = $this->profitPoolCentavos($year);
        $bonus       = 1 + CooperativeAccountModel::PIONEER_DIVIDEND_BONUS / 100;
        $totalWeight = $basics + $pioneers * $bonus;
        $basicShare  = ($poolC > 0 && $totalWeight > 0) ? ($poolC / 100) / $totalWeight : 0.0;
        $allocation  = self::allocate($poolC, $accounts);

        $errors = [];
        if (! self::validYear($year)) {
            $errors[] = 'Choose a year from 2000 to ' . date('Y') . '.';
        }
        if ($poolC <= 0) {
            $errors[] = "There is no profit pool for {$year}: no interest was earned on loans approved that year.";
        }
        if ($accounts === []) {
            $errors[] = 'There are no Active accounts to receive dividends.';
        }

        return [
            'year'              => $year,
            'profit_pool'       => $poolC / 100,
            'basic_count'       => $basics,
            'pioneer_count'     => $pioneers,
            'total_weight'      => $totalWeight,
            'basic_share'       => $basicShare,
            'pioneer_share'     => $basicShare * $bonus,
            'accounts'          => $accounts,
            'allocation'        => $allocation,
            'total_distributed' => array_sum($allocation) / 100,
            'errors'            => $errors,
            'existing'          => $this->liveDistribution($year),
            'legacy_paid'       => $this->legacyDividendsExist($year),
        ];
    }

    /** The Pending or Approved batch for a year, if any. */
    public function liveDistribution(int $year): ?array
    {
        if (! $this->available()) {
            return null;
        }

        return $this->db->table('dividend_distributions')->where('year_lock', $year)->get()->getRowArray() ?: null;
    }

    /** Dividends recorded before batches existed count as a distribution too. */
    private function legacyDividendsExist(int $year): bool
    {
        $q = $this->db->table('dividends')->where('distribution_year', $year)->where('dividend_status !=', 'Rejected');
        if ($this->available()) {
            $q->where('distribution_id IS NULL');
        }

        return $q->countAllResults() > 0;
    }

    /**
     * Create a Pending batch from current data. $expectedPool and
     * $expectedAccounts are what the administrator confirmed; if the data
     * changed since, nothing is created.
     *
     * @return array{0: ?int, 1: ?string} [distributionId, error]
     */
    public function create(int $year, string $date, int $adminId, float $expectedPool, int $expectedAccounts): array
    {
        if (! $this->available()) {
            return [null, 'Apply Database Updates in Settings first.'];
        }
        if (! self::validYear($year)) {
            return [null, 'Choose a year from 2000 to ' . date('Y') . '.'];
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            return [null, 'Enter a valid distribution date.'];
        }

        $plan = $this->plan($year);
        if ($plan['errors'] !== []) {
            return [null, $plan['errors'][0]];
        }
        if ($plan['existing'] || $plan['legacy_paid']) {
            return [null, "Dividends for {$year} have already been distributed or are waiting for approval."];
        }
        if (round($expectedPool, 2) !== round($plan['profit_pool'], 2) || $expectedAccounts !== count($plan['accounts'])) {
            return [null, 'The figures changed since you reviewed them. Check the new amounts and confirm again.'];
        }

        $now    = date('Y-m-d H:i:s');
        $basicC = (int) round($plan['basic_share'] * 100);

        $this->db->transBegin();
        try {
            $this->db->table('dividend_distributions')->insert([
                'distribution_year' => $year,
                'year_lock'         => $year,
                'profit_pool'       => $plan['profit_pool'],
                'basic_count'       => $plan['basic_count'],
                'pioneer_count'     => $plan['pioneer_count'],
                'total_weight'      => round($plan['total_weight'], 4),
                'basic_share'       => round($plan['basic_share'], 6),
                'pioneer_share'     => round($plan['pioneer_share'], 6),
                'total_distributed' => $plan['total_distributed'],
                'distribution_date' => $date,
                'status'            => 'Pending',
                'created_by'        => $adminId,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $distributionId = (int) $this->db->insertID();

            foreach ($plan['allocation'] as $accountId => $centavos) {
                $this->db->table('financial_records')->insert([
                    'account_id'       => $accountId,
                    'record_type'      => 'Dividend',
                    'transaction_date' => $date,
                    'amount'           => $centavos / 100,
                    'approval_status'  => 'Pending',
                    'processed_by'     => $adminId,
                    'reference_id'     => $distributionId,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);
                $frId = (int) $this->db->insertID();

                $this->db->table('dividends')->insert([
                    'distribution_id'        => $distributionId,
                    'account_id'             => $accountId,
                    'distribution_year'      => $year,
                    'profit_pool'            => $plan['profit_pool'],
                    'eligible_account_count' => count($plan['accounts']),
                    'dividend_per_account'   => $basicC / 100,
                    'dividend_amount'        => $centavos / 100,
                    'distribution_date'      => $date,
                    'dividend_status'        => 'Pending',
                    'financial_record_id'    => $frId,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]);
            }

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('The database refused the distribution.');
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            // The unique year_lock key stops a second batch for the same year,
            // including two submissions arriving at the same moment.
            if (stripos($e->getMessage(), 'duplicate') !== false || $this->liveDistribution($year)) {
                return [null, "Dividends for {$year} have already been distributed or are waiting for approval."];
            }
            log_message('error', 'DividendService::create failed: ' . $e->getMessage());
            return [null, 'Could not save the distribution. Nothing was recorded.'];
        }

        return [$distributionId, null];
    }

    /**
     * Approve a Pending batch. One administrator's approval is final: every
     * record is approved, every dividend is Distributed, each record is added
     * to the ledger once and each member is notified. The update only succeeds
     * while the batch is Pending, so it cannot be approved twice.
     *
     * @return array{0: string, 1: bool} [message, ok]
     */
    public function approve(int $distributionId, int $adminId): array
    {
        $batch = $this->db->table('dividend_distributions')->where('distribution_id', $distributionId)->get()->getRowArray();
        if (! $batch || $batch['status'] !== 'Pending') {
            return ['This distribution is no longer waiting for approval.', false];
        }
        $now = date('Y-m-d H:i:s');

        $this->db->transBegin();
        try {
            // A batch half-approved under the old two-step rule keeps its first approver.
            $tier = empty($batch['first_approved_by']) ? 'first' : 'second';
            $this->db->table('dividend_distributions')
                ->where('distribution_id', $distributionId)->where('status', 'Pending')
                ->update(["{$tier}_approved_by" => $adminId, "{$tier}_approval_date" => $now, 'status' => 'Approved', 'updated_at' => $now]);
            if ($this->db->affectedRows() < 1) {
                throw new \DomainException('This distribution was just approved or rejected by another administrator. Reload the page.');
            }
            $this->db->table('financial_records')
                ->where('record_type', 'Dividend')->where('reference_id', $distributionId)->where('approval_status', 'Pending')
                ->update(["{$tier}_approved_by" => $adminId, "{$tier}_approval_date" => $now, 'approval_status' => 'Approved', 'updated_at' => $now]);
            $this->db->table('dividends')
                ->where('distribution_id', $distributionId)
                ->update(['dividend_status' => 'Distributed', 'updated_at' => $now]);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('The database refused the approval.');
            }
            $this->db->transCommit();
        } catch (\DomainException $e) {
            $this->db->transRollback();
            return [$e->getMessage(), false];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'DividendService::approve failed: ' . $e->getMessage());
            return ['Could not approve the distribution. Nothing was changed.', false];
        }

        $this->addToLedger($distributionId);

        $rows = $this->db->table('dividends d')->select('d.dividend_id, d.account_id, d.dividend_amount, ca.account_number')
            ->join('cooperative_accounts ca', 'ca.account_id = d.account_id')
            ->where('d.distribution_id', $distributionId)->get()->getResultArray();
        foreach ($rows as $row) {
            Notifier::toAccountOwner((int) $row['account_id'], 'dividend', 'Dividend distributed',
                'You received a ' . $batch['distribution_year'] . ' dividend of PHP ' . number_format((float) $row['dividend_amount'], 2)
                . ' on account ' . $row['account_number'] . '.', 'member/dividends', 'dividend-' . $row['dividend_id']);
        }

        return ['Distribution approved. Dividends are recorded and added to the blockchain ledger.', true];
    }

    /** Add each approved record of the batch to the ledger, once. */
    private function addToLedger(int $distributionId): void
    {
        $ledger  = new LedgerService();
        $records = $this->db->table('financial_records fr')
            ->select('fr.*')
            ->join('blockchain_ledger bl', 'bl.financial_record_id = fr.financial_record_id', 'left')
            ->where('fr.record_type', 'Dividend')->where('fr.reference_id', $distributionId)
            ->where('fr.approval_status', 'Approved')->where('bl.ledger_id IS NULL')
            ->orderBy('fr.financial_record_id', 'ASC')
            ->get()->getResultArray();

        foreach ($records as $record) {
            try {
                $ledger->createEntry((int) $record['financial_record_id'], $record);
            } catch (\Throwable $e) {
                log_message('error', 'Ledger entry failed for dividend record #' . $record['financial_record_id'] . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Reject a Pending batch. Its records stay for the audit trail, marked
     * Rejected, and the year becomes free to distribute again.
     */
    public function reject(int $distributionId, int $adminId): bool
    {
        $now = date('Y-m-d H:i:s');
        $this->db->transStart();
        $this->db->table('dividend_distributions')
            ->where('distribution_id', $distributionId)->where('status', 'Pending')
            ->update(['status' => 'Rejected', 'year_lock' => null, 'rejected_by' => $adminId, 'rejected_at' => $now, 'updated_at' => $now]);
        $changed = $this->db->affectedRows() > 0;
        if ($changed) {
            $this->db->table('financial_records')
                ->where('record_type', 'Dividend')->where('reference_id', $distributionId)->where('approval_status', 'Pending')
                ->update(['approval_status' => 'Rejected', 'updated_at' => $now]);
            $this->db->table('dividends')
                ->where('distribution_id', $distributionId)
                ->update(['dividend_status' => 'Rejected', 'updated_at' => $now]);
        }
        $this->db->transComplete();

        return $changed && $this->db->transStatus();
    }

    /** Batches, newest first, with who created and approved them. */
    public function distributions(): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->db->table('dividend_distributions d')
            ->select('d.*, uc.username AS created_by_name, u1.username AS first_approver, u2.username AS second_approver, ur.username AS rejected_by_name, COALESCE(u2.username, u1.username) AS approver_name, COALESCE(d.second_approval_date, d.first_approval_date) AS approved_at')
            ->join('users uc', 'uc.user_id = d.created_by', 'left')
            ->join('users u1', 'u1.user_id = d.first_approved_by', 'left')
            ->join('users u2', 'u2.user_id = d.second_approved_by', 'left')
            ->join('users ur', 'ur.user_id = d.rejected_by', 'left')
            ->orderBy('d.distribution_id', 'DESC')
            ->get()->getResultArray();
    }

    /** Dividend rows for a year (all batches), with account and member. */
    public function records(int $year): array
    {
        return $this->db->table('dividends d')
            ->select("d.*, ca.account_number, ca.account_type, CONCAT(m.first_name, ' ', m.last_name) AS member_name")
            ->join('cooperative_accounts ca', 'ca.account_id = d.account_id', 'left')
            ->join('members m', 'm.member_id = ca.member_id', 'left')
            ->where('d.distribution_year', $year)
            ->orderBy('d.dividend_id', 'DESC')
            ->get()->getResultArray();
    }
}
