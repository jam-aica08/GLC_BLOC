<?php

namespace App\Models;

use CodeIgniter\Model;

class CooperativeAccountModel extends Model
{
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
        'account_status'      => 'permit_empty|in_list[Active,Inactive,Closed]',
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
