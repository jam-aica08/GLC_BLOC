<?php

namespace App\Models;

use CodeIgniter\Model;

class LoanModel extends Model
{
    protected $table            = 'loans';
    protected $primaryKey       = 'loan_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'account_id',
        'total_contributions',
        'maximum_loan_limit',
        'loan_amount',
        'interest_rate',
        'interest_amount',
        'loan_term_months',
        'application_date',
        'approval_date',
        'release_date',
        'loan_status',
        'approved_by',
    ];

    // Timestamps
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // Validation
    protected $validationRules = [
        'account_id'          => 'required|integer|greater_than[0]',
        'loan_amount'         => 'required|decimal|greater_than[0]',
        'interest_rate'       => 'required|decimal|greater_than_equal_to[0]',
        'loan_term_months'    => 'required|integer|greater_than[0]',
        'application_date'    => 'required|valid_date',
        'approval_date'       => 'permit_empty|valid_date',
        'release_date'        => 'permit_empty|valid_date',
        'loan_status'         => 'permit_empty|in_list[Pending,Approved,Active,Paid,Rejected,Defaulted]',
        'total_contributions' => 'permit_empty|decimal|greater_than_equal_to[0]',
        'maximum_loan_limit'  => 'permit_empty|decimal|greater_than_equal_to[0]',
        'interest_amount'     => 'permit_empty|decimal|greater_than_equal_to[0]',
        'approved_by'         => 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'account_id' => [
            'required' => 'Account ID is required.',
        ],
        'loan_amount' => [
            'required'     => 'Loan amount is required.',
            'decimal'      => 'Loan amount must be a valid decimal number.',
            'greater_than' => 'Loan amount must be greater than zero.',
        ],
        'interest_rate' => [
            'required' => 'Interest rate is required.',
        ],
        'loan_term_months' => [
            'required'     => 'Loan term is required.',
            'integer'      => 'Loan term must be a whole number of months.',
            'greater_than' => 'Loan term must be at least 1 month.',
        ],
        'application_date' => [
            'required'   => 'Application date is required.',
            'valid_date' => 'Please provide a valid application date.',
        ],
        'loan_status' => [
            'in_list' => 'Loan status must be one of: Pending, Approved, Active, Paid, Rejected, Defaulted.',
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
     * Retrieve all loans with Pending status along with member and account details.
     *
     * @return array
     */
    public function getPendingLoans(): array
    {
        return $this->select(
                        'loans.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = loans.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('loans.loan_status', 'Pending')
                    ->orderBy('loans.application_date', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve all loans with Active status along with member and account details.
     *
     * @return array
     */
    public function getActiveLoans(): array
    {
        return $this->select(
                        'loans.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = loans.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('loans.loan_status', 'Active')
                    ->orderBy('loans.release_date', 'DESC')
                    ->findAll();
    }

    /**
     * Retrieve all loans associated with a specific cooperative account.
     *
     * @param  int $account_id
     * @return array
     */
    public function getLoansByAccount(int $account_id): array
    {
        return $this->where('account_id', $account_id)
                    ->orderBy('application_date', 'DESC')
                    ->findAll();
    }

    /**
     * Retrieve a loan with its full details including approver information.
     *
     * @param  int $loan_id
     * @return array|null
     */
    public function getLoanWithDetails(int $loan_id): ?array
    {
        return $this->select(
                        'loans.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name, ' .
                        'au.username AS approved_by_username'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = loans.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->join('users AS au', 'au.user_id = loans.approved_by', 'left')
                    ->where('loans.loan_id', $loan_id)
                    ->first();
    }
}
