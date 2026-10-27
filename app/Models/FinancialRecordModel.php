<?php

namespace App\Models;

use CodeIgniter\Model;

class FinancialRecordModel extends Model
{
    protected $table            = 'financial_records';
    protected $primaryKey       = 'financial_record_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'account_id',
        'record_type',
        'transaction_date',
        'amount',
        'approval_status',
        'processed_by',
        'first_approved_by',
        'first_approval_date',
        'second_approved_by',
        'second_approval_date',
        'reference_id',
    ];

    // Timestamps
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // Validation
    protected $validationRules = [
        'account_id'       => 'required|integer|greater_than[0]',
        'record_type'      => 'required|in_list[Contribution,Loan Disbursement,Loan Payment,Dividend,Withdrawal,Loan Offset]',
        'transaction_date' => 'required|valid_date',
        'amount'           => 'required|decimal|greater_than[0]',
        'approval_status'  => 'permit_empty|in_list[Pending,Approved,Rejected]',
        'processed_by'     => 'permit_empty|integer|greater_than[0]',
        'first_approved_by'=> 'permit_empty|integer|greater_than[0]',
        'first_approval_date' => 'permit_empty',
        'second_approved_by'  => 'permit_empty|integer|greater_than[0]',
        'second_approval_date'=> 'permit_empty',
        'reference_id'     => 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'account_id' => [
            'required' => 'Account ID is required.',
        ],
        'record_type' => [
            'required' => 'Record type is required.',
            'in_list'  => 'Record type must be one of: Contribution, Loan Disbursement, Loan Payment, Dividend, Withdrawal, Loan Offset.',
        ],
        'transaction_date' => [
            'required'   => 'Transaction date is required.',
            'valid_date' => 'Please provide a valid transaction date.',
        ],
        'amount' => [
            'required'     => 'Amount is required.',
            'decimal'      => 'Amount must be a valid decimal number.',
            'greater_than' => 'Amount must be greater than zero.',
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
     * Retrieve all financial records with Pending approval status,
     * including account and member details.
     *
     * @return array
     */
    public function getPendingRecords(): array
    {
        return $this->select(
                        'financial_records.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name, ' .
                        'pu.username AS processed_by_username'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = financial_records.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->join('users AS pu', 'pu.user_id = financial_records.processed_by', 'left')
                    ->where('financial_records.approval_status', 'Pending')
                    ->orderBy('financial_records.transaction_date', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve all financial records with Approved approval status,
     * including account and member details.
     *
     * @return array
     */
    public function getApprovedRecords(): array
    {
        return $this->select(
                        'financial_records.*, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name, ' .
                        'fa.username AS first_approved_by_username, ' .
                        'sa.username AS second_approved_by_username'
                    )
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = financial_records.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->join('users AS fa', 'fa.user_id = financial_records.first_approved_by', 'left')
                    ->join('users AS sa', 'sa.user_id = financial_records.second_approved_by', 'left')
                    ->where('financial_records.approval_status', 'Approved')
                    ->orderBy('financial_records.transaction_date', 'DESC')
                    ->findAll();
    }

    /**
     * Retrieve all financial records for a specific cooperative account,
     * ordered by transaction date descending.
     *
     * @param  int $account_id
     * @return array
     */
    public function getRecordsByAccount(int $account_id): array
    {
        return $this->where('account_id', $account_id)
                    ->orderBy('transaction_date', 'DESC')
                    ->findAll();
    }

    /**
     * Calculate the total amount for all approved records of a given type.
     *
     * @param  string $record_type  One of: Contribution, Loan Disbursement, Loan Payment, Dividend
     * @return float
     */
    public function getTotalApprovedByType(string $record_type): float
    {
        $row = $this->db->table($this->table)
                        ->selectSum('amount', 'total')
                        ->where('record_type', $record_type)
                        ->where('approval_status', 'Approved')
                        ->get()
                        ->getRow();

        return $row && $row->total !== null ? (float) $row->total : 0.0;
    }

    /**
     * Retrieve records for a specific account filtered by type.
     *
     * @param  int    $account_id
     * @param  string $record_type
     * @return array
     */
    public function getRecordsByAccountAndType(int $account_id, string $record_type): array
    {
        return $this->where('account_id', $account_id)
                    ->where('record_type', $record_type)
                    ->orderBy('transaction_date', 'DESC')
                    ->findAll();
    }
}
