<?php

namespace App\Models;

use CodeIgniter\Model;

class DividendModel extends Model
{
    protected $table            = 'dividends';
    protected $primaryKey       = 'dividend_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'account_id',
        'distribution_year',
        'profit_pool',
        'eligible_account_count',
        'dividend_per_account',
        'dividend_amount',
        'distribution_date',
        'dividend_status',
        'financial_record_id',
    ];

    // Timestamps
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // Validation
    protected $validationRules = [
        'account_id'            => 'required|integer|greater_than[0]',
        'distribution_year'     => 'required|integer|greater_than[1999]',
        'profit_pool'           => 'permit_empty|decimal|greater_than_equal_to[0]',
        'eligible_account_count'=> 'permit_empty|integer|greater_than_equal_to[0]',
        'dividend_per_account'  => 'permit_empty|decimal|greater_than_equal_to[0]',
        'dividend_amount'       => 'required|decimal|greater_than_equal_to[0]',
        'distribution_date'     => 'permit_empty|valid_date',
        'dividend_status'       => 'permit_empty|in_list[Pending,Approved,Distributed]',
        'financial_record_id'   => 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'account_id' => [
            'required' => 'Account ID is required.',
        ],
        'distribution_year' => [
            'required'     => 'Distribution year is required.',
            'integer'      => 'Distribution year must be a valid year.',
            'greater_than' => 'Distribution year must be after 1999.',
        ],
        'dividend_amount' => [
            'required'              => 'Dividend amount is required.',
            'decimal'               => 'Dividend amount must be a valid decimal number.',
            'greater_than_equal_to' => 'Dividend amount cannot be negative.',
        ],
        'dividend_status' => [
            'in_list' => 'Dividend status must be one of: Pending, Approved, Distributed.',
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
     * Retrieve all dividends for a given distribution year,
     * with account and member details.
     *
     * @param  int $year  Four-digit year (e.g. 2026)
     * @return array
     */
    public function getDividendsByYear(int $year): array
    {
        return $this->select(
                        'dividends.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = dividends.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('dividends.distribution_year', $year)
                    ->orderBy('members.last_name', 'ASC')
                    ->orderBy('members.first_name', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve all dividends with Pending status, with account and member details.
     *
     * @return array
     */
    public function getPendingDividends(): array
    {
        return $this->select(
                        'dividends.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = dividends.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('dividends.dividend_status', 'Pending')
                    ->orderBy('dividends.distribution_year', 'DESC')
                    ->findAll();
    }

    /**
     * Calculate the total dividend amount distributed for a given year.
     *
     * @param  int $year
     * @return float
     */
    public function getTotalDistributedByYear(int $year): float
    {
        $row = $this->db->table($this->table)
                        ->selectSum('dividend_amount', 'total')
                        ->where('distribution_year', $year)
                        ->where('dividend_status', 'Distributed')
                        ->get()
                        ->getRow();

        return $row && $row->total !== null ? (float) $row->total : 0.0;
    }

    /**
     * Retrieve all dividends for a specific account.
     *
     * @param  int $account_id
     * @return array
     */
    public function getDividendsByAccount(int $account_id): array
    {
        return $this->where('account_id', $account_id)
                    ->orderBy('distribution_year', 'DESC')
                    ->findAll();
    }
}
