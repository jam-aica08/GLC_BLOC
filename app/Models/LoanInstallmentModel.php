<?php

namespace App\Models;

use CodeIgniter\Model;

class LoanInstallmentModel extends Model
{
    protected $table            = 'loan_installments';
    protected $primaryKey       = 'installment_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'loan_id',
        'installment_number',
        'due_date',
        'principal_amount',
        'interest_amount',
        'amount_due',
        'carried_over',
        'amount_paid',
        'remaining_balance',
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
        'loan_id'            => 'required|integer|greater_than[0]',
        'installment_number' => 'required|integer|greater_than[0]',
        'due_date'           => 'required|valid_date',
        'principal_amount'   => 'required|decimal|greater_than_equal_to[0]',
        'interest_amount'    => 'required|decimal|greater_than_equal_to[0]',
        'amount_due'         => 'required|decimal|greater_than_equal_to[0]',
        'amount_paid'        => 'permit_empty|decimal|greater_than_equal_to[0]',
        'remaining_balance'  => 'permit_empty|decimal|greater_than_equal_to[0]',
        'payment_date'       => 'permit_empty|valid_date',
        'payment_status'     => 'permit_empty|in_list[Upcoming,Due,Late,Paid,Partial]',
        'financial_record_id'=> 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'loan_id' => [
            'required' => 'Loan ID is required.',
        ],
        'installment_number' => [
            'required' => 'Installment number is required.',
        ],
        'due_date' => [
            'required'   => 'Due date is required.',
            'valid_date' => 'Please provide a valid due date.',
        ],
        'principal_amount' => [
            'required' => 'Principal amount is required.',
        ],
        'interest_amount' => [
            'required' => 'Interest amount is required.',
        ],
        'amount_due' => [
            'required' => 'Amount due is required.',
        ],
        'payment_status' => [
            'in_list' => 'Payment status must be one of: Upcoming, Due, Late, Paid, Partial.',
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
     * Retrieve all installments for a specific loan, ordered by installment number.
     *
     * @param  int $loan_id
     * @return array
     */
    public function getInstallmentsByLoan(int $loan_id): array
    {
        return $this->where('loan_id', $loan_id)
                    ->orderBy('installment_number', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve upcoming (not yet due or paid) installments for a specific loan.
     *
     * @param  int $loan_id
     * @return array
     */
    public function getUpcomingInstallments(int $loan_id): array
    {
        return $this->where('loan_id', $loan_id)
                    ->whereIn('payment_status', ['Upcoming', 'Due'])
                    ->orderBy('due_date', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve all installments that are due today or past due (Late/Due status)
     * across all loans.
     *
     * @param  string|null $date  Date in Y-m-d format; defaults to today
     * @return array
     */
    public function getDueInstallments(?string $date = null): array
    {
        $today = $date ?? date('Y-m-d');

        return $this->select(
                        'loan_installments.*, ' .
                        'loans.loan_amount, loans.loan_status, ' .
                        'cooperative_accounts.account_number, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('loans', 'loans.loan_id = loan_installments.loan_id', 'left')
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = loans.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('loan_installments.due_date <=', $today)
                    ->whereIn('loan_installments.payment_status', ['Due', 'Late'])
                    ->orderBy('loan_installments.due_date', 'ASC')
                    ->findAll();
    }

    /**
     * Count unpaid installments for a specific loan.
     *
     * @param  int $loan_id
     * @return int
     */
    public function countUnpaidInstallments(int $loan_id): int
    {
        return $this->where('loan_id', $loan_id)
                    ->whereNotIn('payment_status', ['Paid'])
                    ->countAllResults();
    }
}
