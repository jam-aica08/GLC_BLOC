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
        $paid               = $this->getTotalContributions((int) $account['account_id']);

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
     * Smallest contribution allowed for this account right now. While the
     * account is Pending it is whatever is still needed to activate it
     * (contributions already waiting for approval count); once Active it is
     * PHP 300.
     */
    public function minimumContribution(array $account): float
    {
        if (($account['account_status'] ?? '') !== 'Pending') {
            return self::CONTRIBUTION_MIN;
        }

        $pending = (float) ($this->db->table('contributions')
            ->selectSum('amount', 'total')
            ->where('account_id', (int) $account['account_id'])
            ->where('payment_status', 'Pending')
            ->get()->getRow()->total ?? 0);
        $remaining = $this->getStanding($account)['join_balance'] - $pending;

        return round(min(self::CONTRIBUTION_MAX, max(self::CONTRIBUTION_MIN, $remaining)), 2);
    }

    /**
     * Move a Pending account to Active once its January catch-up is fully paid.
     */
    public function activateIfComplete(int $accountId): bool
    {
        $account = $this->find($accountId);
        if (! $account || $account['account_status'] !== 'Pending') {
            return false;
        }
        if ($this->getStanding($account)['join_balance'] > 0) {
            return false;
        }

        $this->db->table($this->table)
                 ->where('account_id', $accountId)
                 ->update(['account_status' => 'Active']);

        return true;
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
            'financial_record_id' => $this->db->insertID(),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
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
