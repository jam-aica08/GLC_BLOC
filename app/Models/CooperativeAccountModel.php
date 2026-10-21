<?php

namespace App\Models;

use CodeIgniter\Model;

class CooperativeAccountModel extends Model
{
    public const ACCOUNT_TYPES = ['Pioneer', 'Basic'];

    /** Required contribution per account per month (PHP), for every account type. */
    public const MONTHLY_CONTRIBUTION = 300.00;

    /** Loan interest rate (%) per account type. */
    public const LOAN_INTEREST_RATE = ['Pioneer' => 2.00, 'Basic' => 5.00];

    /** Pioneer accounts receive this much more (%) than a Basic share of dividends. */
    public const PIONEER_DIVIDEND_BONUS = 2.00;

    /** Allowed amount for any single contribution, including the opening one (PHP). */
    public const CONTRIBUTION_MIN = 300.00;
    public const CONTRIBUTION_MAX = 3600.00;

    protected $table            = 'cooperative_accounts';
    protected $primaryKey       = 'account_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'member_id',
        'account_number',
        'account_type',
        'account_start_date',
        'monthly_contribution',
        'account_status',
    ];

    // Timestamps
    protected $useTimestamps = false;

    // Validation
    protected $validationRules = [
        'member_id'           => 'required|integer|greater_than[0]',
        'account_number'      => 'required|max_length[30]|is_unique[cooperative_accounts.account_number,account_id,{account_id}]',
        'account_type'        => 'required|in_list[Pioneer,Basic]',
        'account_start_date'  => 'required|valid_date',
        'monthly_contribution'=> 'permit_empty|decimal|greater_than_equal_to[0]',
        'account_status'      => 'permit_empty|in_list[Active,Inactive,Closed,Pending]',
    ];

    protected $validationMessages = [
        'member_id' => [
            'required' => 'Member ID is required.',
        ],
        'account_number' => [
            'required'  => 'Account number is required.',
            'is_unique' => 'This account number already exists.',
        ],
        'account_type' => [
            'required' => 'Account type is required.',
            'in_list'  => 'Account type must be Pioneer or Basic.',
        ],
        'account_start_date' => [
            'required'   => 'Account start date is required.',
            'valid_date' => 'Please provide a valid start date.',
        ],
    ];

    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Generate the next account number in the format ACC-YYYY-NNN.
     * The numeric suffix resets per year.
     *
     * @return string e.g. 'ACC-2026-002'
     */
    public function getNextAccountNumber(): string
    {
        $year   = date('Y');
        $prefix = 'ACC-' . $year . '-';

        $row = $this->db->table($this->table)
                        ->select("MAX(CAST(SUBSTRING(account_number, " . (strlen($prefix) + 1) . ") AS UNSIGNED)) AS max_num")
                        ->like('account_number', $prefix, 'after')
                        ->get()
                        ->getRow();

        $next = ($row && $row->max_num !== null) ? (int) $row->max_num + 1 : 1;

        return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Retrieve all cooperative accounts belonging to a specific member.
     *
     * @param  int $member_id
     * @return array
     */
    public function getAccountsByMember(int $member_id): array
    {
        return $this->where('member_id', $member_id)->findAll();
    }

    /**
     * Calculate the total approved contributions for a specific account.
     *
     * @param  int $account_id
     * @return float
     */
    public function getTotalContributions(int $account_id): float
    {
        $row = $this->db->table('contributions')
                        ->selectSum('amount', 'total')
                        ->where('account_id', $account_id)
                        ->where('payment_status', 'Approved')
                        ->get()
                        ->getRow();

        return $row && $row->total !== null ? (float) $row->total : 0.0;
    }

    /**
     * Add each account's approved and waiting contribution totals
     * (approved_total, pending_total) with one query, so getStanding() and
     * amountLeftToActivate() do not query once per account.
     */
    public function withTotals(array $accounts): array
    {
        $ids = array_map('intval', array_column($accounts, 'account_id'));
        $totals = [];
        if ($ids !== []) {
            $rows = $this->db->table('contributions')
                ->select('account_id, payment_status, SUM(amount) AS total')
                ->whereIn('account_id', $ids)->whereIn('payment_status', ['Approved', 'Pending'])
                ->groupBy(['account_id', 'payment_status'])
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $totals[(int) $r['account_id']][$r['payment_status']] = (float) $r['total'];
            }
        }
        foreach ($accounts as &$acc) {
            $acc['approved_total'] = $totals[(int) $acc['account_id']]['Approved'] ?? 0.0;
            $acc['pending_total']  = $totals[(int) $acc['account_id']]['Pending'] ?? 0.0;
        }
        unset($acc);

        return $accounts;
    }

    /**
     * Contribution standing for an account. Every account joins the
     * cooperative only after paying every month from January of its start
     * year up to and including its start month (an account opened in
     * October needs PHP 3,000). After that each account owes PHP 300 a month.
     *
     * @return array{required_to_join: float, required_to_date: float, paid: float,
     *               join_balance: float, due_balance: float}
     */
    public function getStanding(array $account): array
    {
        $start      = strtotime($account['account_start_date'] ?: 'now');
        $startYear  = (int) date('Y', $start);
        $startMonth = (int) date('n', $start);

        $monthsSinceJanuary = ((int) date('Y') - $startYear) * 12 + (int) date('n');
        $requiredToJoin     = self::joinAmount($account['account_start_date'] ?: null);
        $requiredToDate     = max($monthsSinceJanuary, $startMonth) * self::MONTHLY_CONTRIBUTION;
        $paid               = isset($account['approved_total'])
            ? (float) $account['approved_total']
            : $this->getTotalContributions((int) $account['account_id']);

        return [
            'required_to_join' => $requiredToJoin,
            'required_to_date' => $requiredToDate,
            'paid'             => $paid,
            'join_balance'     => max(0.0, round($requiredToJoin - $paid, 2)),
            'due_balance'      => max(0.0, round($requiredToDate - $paid, 2)),
        ];
    }

    /**
     * What an account opened on $date must pay before it becomes active:
     * PHP 300 for every month from January to the opening month.
     */
    public static function joinAmount(?string $date = null): float
    {
        return (int) date('n', $date ? strtotime($date) : time()) * self::MONTHLY_CONTRIBUTION;
    }

    /**
     * Smallest contribution allowed for this account right now: PHP 300.
     * A Pending account can be paid in parts; only when less than PHP 300
     * is left to activate it (contributions waiting for approval count) is
     * the minimum that remainder.
     */
    public function minimumContribution(array $account): float
    {
        $remaining = $this->amountLeftToActivate($account);

        return ($remaining > 0 && $remaining < self::CONTRIBUTION_MIN) ? $remaining : self::CONTRIBUTION_MIN;
    }

    /**
     * What a Pending account still needs before it can be activated, after
     * approved and waiting contributions. 0 for an Active account.
     */
    public function amountLeftToActivate(array $account): float
    {
        if (($account['account_status'] ?? '') !== 'Pending') {
            return 0.0;
        }

        $pending = isset($account['pending_total'])
            ? (float) $account['pending_total']
            : (float) ($this->db->table('contributions')
                ->selectSum('amount', 'total')
                ->where('account_id', (int) $account['account_id'])
                ->where('payment_status', 'Pending')
                ->get()->getRow()->total ?? 0);

        return round(max(0.0, $this->getStanding($account)['join_balance'] - $pending), 2);
    }

    /**
     * Make every account's status match its approved contributions: an
     * account is Active only when January up to its opening month is paid
     * and approved, otherwise it is Pending. Closed and Inactive accounts
     * are left alone.
     */
    public function syncStatuses(): void
    {
        $paid = '(SELECT COALESCE(SUM(c.amount), 0) FROM contributions c'
              . ' WHERE c.account_id = cooperative_accounts.account_id AND c.payment_status = \'Approved\')';
        $join = 'MONTH(account_start_date) * ' . self::MONTHLY_CONTRIBUTION;

        $wrongActive  = "account_status = 'Active' AND {$paid} < {$join}";
        $wrongPending = "account_status = 'Pending' AND {$paid} >= {$join}";

        // Usually nothing is out of line, so look first and write only when needed.
        $mismatch = $this->db->query("SELECT 1 FROM cooperative_accounts WHERE ({$wrongActive}) OR ({$wrongPending}) LIMIT 1")->getRow();
        if (! $mismatch) {
            return;
        }
        $this->db->query("UPDATE cooperative_accounts SET account_status = 'Pending' WHERE {$wrongActive}");
        $this->db->query("UPDATE cooperative_accounts SET account_status = 'Active' WHERE {$wrongPending}");
    }

    /**
     * Open a new account. It stays Pending until contributions are complete
     * from January of the current year.
     */
    public function openAccount(int $memberId, string $accountType): string
    {
        $accountNumber = $this->getNextAccountNumber();

        $this->db->table($this->table)->insert([
            'member_id'            => $memberId,
            'account_number'       => $accountNumber,
            'account_type'         => $accountType,
            'account_start_date'   => date('Y-m-d'),
            'monthly_contribution' => self::MONTHLY_CONTRIBUTION,
            'account_status'       => 'Pending',
        ]);

        return $accountNumber;
    }

    /**
     * Record the opening contribution of a new account as a pending
     * contribution. It counts toward activating the account once both
     * admins approve it in Contributions.
     */
    public function recordOpeningContribution(string $accountNumber, float $amount, int $processedBy): void
    {
        $accountId = (int) $this->db->table($this->table)->select('account_id')
                                    ->where('account_number', $accountNumber)
                                    ->get()->getRowArray()['account_id'];
        $now = date('Y-m-d H:i:s');

        $this->db->table('financial_records')->insert([
            'account_id'       => $accountId,
            'record_type'      => 'Contribution',
            'transaction_date' => date('Y-m-d'),
            'amount'           => $amount,
            'approval_status'  => 'Pending',
            'processed_by'     => $processedBy,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->db->table('contributions')->insert([
            'account_id'          => $accountId,
            'contribution_period' => date('Y-m'),
            'amount'              => $amount,
            'payment_date'        => date('Y-m-d'),
            'payment_status'      => 'Pending',
            'financial_record_id' => $frId = $this->db->insertID(),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $owner = $this->db->table($this->table)->select('member_id')->where('account_id', $accountId)->get()->getRowArray();
        \App\Services\Notifier::toMember((int) ($owner['member_id'] ?? 0), 'contribution', 'Opening contribution recorded',
            'An opening contribution of PHP ' . number_format($amount, 2) . " for account {$accountNumber} was recorded and is waiting for approval.",
            'member/contributions', 'contribution-received-' . $frId);
    }

    /**
     * Retrieve an account together with its member and user details.
     *
     * @param  int $account_id
     * @return array|null
     */
    public function getAccountWithMember(int $account_id): ?array
    {
        return $this->select('cooperative_accounts.*, members.first_name, members.last_name, members.member_number, users.email, users.username')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->join('users', 'users.user_id = members.user_id', 'left')
                    ->where('cooperative_accounts.account_id', $account_id)
                    ->first();
    }

    /**
     * Retrieve all active accounts.
     *
     * @return array
     */
    public function getActiveAccounts(): array
    {
        return $this->where('account_status', 'Active')->findAll();
    }
}
