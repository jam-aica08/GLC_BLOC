<?php

namespace App\Models;

use CodeIgniter\Model;

class BlockchainLedgerModel extends Model
{
    protected $table            = 'blockchain_ledger';
    protected $primaryKey       = 'ledger_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'financial_record_id',
        'transaction_hash',
        'previous_hash',
        'ledger_sequence',
        'recorded_at',
        'verification_status',
    ];

    // Timestamps disabled: blockchain records are immutable and
    // recorded_at is managed explicitly.
    protected $useTimestamps = false;

    // Validation
    protected $validationRules = [
        'financial_record_id' => 'required|integer|greater_than[0]',
        'transaction_hash'    => 'required|exact_length[64]',
        'previous_hash'       => 'permit_empty|exact_length[64]',
        'ledger_sequence'     => 'required|integer|greater_than_equal_to[1]',
        'recorded_at'         => 'required',
        'verification_status' => 'permit_empty|in_list[Valid,Tampered]',
    ];

    protected $validationMessages = [
        'financial_record_id' => [
            'required' => 'Financial record ID is required.',
        ],
        'transaction_hash' => [
            'required'     => 'Transaction hash is required.',
            'exact_length' => 'Transaction hash must be exactly 64 characters (SHA-256).',
        ],
        'ledger_sequence' => [
            'required'              => 'Ledger sequence is required.',
            'integer'               => 'Ledger sequence must be an integer.',
            'greater_than_equal_to' => 'Ledger sequence must be at least 1.',
        ],
        'recorded_at' => [
            'required' => 'Recorded-at timestamp is required.',
        ],
        'verification_status' => [
            'in_list' => 'Verification status must be Valid or Tampered.',
        ],
    ];

    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks — inserts are intentionally not modified after creation.
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
     * Retrieve the most recent ledger entry (highest ledger_sequence).
     *
     * @return array|null
     */
    public function getLatestEntry(): ?array
    {
        return $this->orderBy('ledger_sequence', 'DESC')->first();
    }

    /**
     * Retrieve all ledger entries in ascending sequence order,
     * suitable for full chain verification.
     *
     * @return array
     */
    public function getAllInSequence(): array
    {
        return $this->orderBy('ledger_sequence', 'ASC')->findAll();
    }

    /**
     * Retrieve the ledger entry (or entries) linked to a specific financial record.
     * In normal operation there should be exactly one entry per financial record,
     * but the method returns an array to support edge-case introspection.
     *
     * @param  int $financial_record_id
     * @return array
     */
    public function getByFinancialRecord(int $financial_record_id): array
    {
        return $this->where('financial_record_id', $financial_record_id)
                    ->orderBy('ledger_sequence', 'ASC')
                    ->findAll();
    }

    /**
     * Retrieve ledger entries joined with their financial record details.
     *
     * @param  int $limit  Number of entries to return (0 = all)
     * @return array
     */
    public function getEntriesWithFinancialDetails(int $limit = 0): array
    {
        $builder = $this->select(
                            'blockchain_ledger.*, ' .
                            'financial_records.record_type, financial_records.amount, ' .
                            'financial_records.transaction_date, financial_records.approval_status, ' .
                            'cooperative_accounts.account_number'
                        )
                        ->join('financial_records', 'financial_records.financial_record_id = blockchain_ledger.financial_record_id', 'left')
                        ->join('cooperative_accounts', 'cooperative_accounts.account_id = financial_records.account_id', 'left')
                        ->orderBy('blockchain_ledger.ledger_sequence', 'ASC');

        if ($limit > 0) {
            $builder->limit($limit);
        }

        return $builder->findAll();
    }

    /**
     * Mark a ledger entry as Tampered.
     *
     * @param  int $ledger_id
     * @return bool
     */
    public function markAsTampered(int $ledger_id): bool
    {
        return $this->update($ledger_id, ['verification_status' => 'Tampered']);
    }

    /**
     * Count all ledger entries with a given verification status.
     *
     * @param  string $status  'Valid' or 'Tampered'
     * @return int
     */
    public function countByVerificationStatus(string $status): int
    {
        return $this->where('verification_status', $status)->countAllResults();
    }
}
