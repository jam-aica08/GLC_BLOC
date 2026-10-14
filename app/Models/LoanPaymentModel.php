<?php

namespace App\Models;

use CodeIgniter\Model;

class LoanPaymentModel extends Model
{
    protected $table            = 'loan_payments';
    protected $primaryKey       = 'payment_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'loan_id',
        'payment_date',
        'amount_paid',
        'principal_amount',
        'interest_amount',
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
        'payment_date'       => 'required|valid_date',
        'amount_paid'        => 'required|decimal|greater_than[0]',
        'principal_amount'   => 'permit_empty|decimal|greater_than_equal_to[0]',
        'interest_amount'    => 'permit_empty|decimal|greater_than_equal_to[0]',
        'payment_status'     => 'permit_empty|in_list[Pending,Approved,Rejected]',
        'financial_record_id'=> 'permit_empty|integer|greater_than[0]',
    ];

    protected $validationMessages = [
        'loan_id' => [
            'required' => 'Loan ID is required.',
        ],
        'payment_date' => [
            'required'   => 'Payment date is required.',
            'valid_date' => 'Please provide a valid payment date.',
        ],
        'amount_paid' => [
            'required'     => 'Amount paid is required.',
            'decimal'      => 'Amount paid must be a valid decimal number.',
            'greater_than' => 'Amount paid must be greater than zero.',
        ],
        'payment_status' => [
            'in_list' => 'Payment status must be one of: Pending, Approved, Rejected.',
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
     * Retrieve all loan payments with Pending status,
     * along with loan, account and member details for review.
     *
     * @return array
     */
    public function getPendingPayments(): array
    {
        return $this->select(
                        'loan_payments.*, ' .
                        'loans.loan_amount, loans.loan_status, loans.loan_term_months, ' .
                        'cooperative_accounts.account_number, cooperative_accounts.account_type, ' .
                        'members.member_number, members.first_name, members.last_name'
                    )
                    ->join('loans', 'loans.loan_id = loan_payments.loan_id', 'left')
                    ->join('cooperative_accounts', 'cooperative_accounts.account_id = loans.account_id', 'left')
                    ->join('members', 'members.member_id = cooperative_accounts.member_id', 'left')
                    ->where('loan_payments.payment_status', 'Pending')
                    ->orderBy('loan_payments.payment_date', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve all payments for a specific loan, ordered by payment date descending.
     *
     * @param  int $loan_id
     * @return array
     */
    public function getPaymentsByLoan(int $loan_id): array
    {
        return $this->where('loan_id', $loan_id)
                    ->orderBy('payment_date', 'DESC')
                    ->findAll();
    }

    /**
     * Calculate the total amount paid (approved) for a specific loan.
     *
     * @param  int $loan_id
     * @return float
     */
    public function getTotalPaidForLoan(int $loan_id): float
    {
        $row = $this->db->table($this->table)
                        ->selectSum('amount_paid', 'total')
                        ->where('loan_id', $loan_id)
                        ->where('payment_status', 'Approved')
                        ->get()
                        ->getRow();

        return $row && $row->total !== null ? (float) $row->total : 0.0;
    }

    /**
     * Retrieve a payment with its associated installment breakdown.
     *
     * @param  int $payment_id
     * @return array|null
     */
    public function getPaymentWithInstallments(int $payment_id): ?array
    {
        $payment = $this->find($payment_id);

        if ($payment === null) {
            return null;
        }

        $installments = $this->db->table('loan_payment_installments')
                                 ->select('loan_payment_installments.*, loan_installments.installment_number, loan_installments.due_date')
                                 ->join('loan_installments', 'loan_installments.installment_id = loan_payment_installments.installment_id', 'left')
                                 ->where('payment_id', $payment_id)
                                 ->get()
                                 ->getResultArray();

        $payment['installments'] = $installments;

        return $payment;
    }
}
