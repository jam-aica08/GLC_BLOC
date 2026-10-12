<?php

namespace App\Models;

use CodeIgniter\Model;

class ContributionModel extends Model
{
    protected $table            = 'contributions';
    protected $primaryKey       = 'contribution_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'account_id',
        'contribution_period',
        'amount',
        'payment_date',
        'payment_status',
        'financial_record_id',
    ];

    // Timestamps
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // Validation
    protected $validationRules = [
        'account_id'          => 'required|integer|greater_than[0]',
        'contribution_period' => 'required|max_length[7]|regex_match[/^\d{4}-\d{2}$/]',
        'amount'              => 'permit_empty|decimal|greater_than_equal_to[0]',
        'payment_date'        => 'permit_empty|valid_date',
        'payment_status'      => 'permit_empty|in_list[Pending,Approved,Rejected]',
        'financial_record_id' => 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'account_id' => [
            'required' => 'Account ID is required.',
        ],
        'contribution_period' => [
            'required'    => 'Contribution period is required.',
            'regex_match' => 'Contribution period must be in YYYY-MM format.',
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
     * Retrieve all pending contributions with their associated account
     * and member details for review workflows.
     *
     * @return array
     */
    public function getPendingContributions(): array
    {
        return $this->select(
                        'contributions.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = contributions.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('contributions.payment_status', 'Pending')
                    ->orderBy('contributions.created_at', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve all contributions for a specific cooperative account,
     * ordered by period descending (most recent first).
     *
     * @param  int $account_id
     * @return array
     */
    public function getContributionsByAccount(int $account_id): array
    {
        return $this->where('account_id', $account_id)
                    ->orderBy('contribution_period', 'DESC')
                    ->findAll();
    }

    /**
     * Check whether a contribution already exists for a given
     * account and period combination.
     *
     * @param  int    $account_id
     * @param  string $contribution_period  YYYY-MM
     * @return bool
     */
    public function contributionExistsForPeriod(int $account_id, string $contribution_period): bool
    {
        return $this->where('account_id', $account_id)
                    ->where('contribution_period', $contribution_period)
                    ->countAllResults() > 0;
    }

    /**
     * Retrieve approved contributions for a specific account.
     *
     * @param  int $account_id
     * @return array
     */
    public function getApprovedContributionsByAccount(int $account_id): array
    {
        return $this->where('account_id', $account_id)
                    ->where('payment_status', 'Approved')
                    ->orderBy('contribution_period', 'ASC')
                    ->findAll();
    }
}
