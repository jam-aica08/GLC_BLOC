<?php

namespace App\Models;

use CodeIgniter\Model;

class MemberModel extends Model
{
    protected $table            = 'members';
    protected $primaryKey       = 'member_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'user_id',
        'member_number',
        'first_name',
        'last_name',
        'membership_date',
        'membership_status',
    ];

    // Timestamps
    protected $useTimestamps = false;

    // Validation
    protected $validationRules = [
        'user_id'          => 'required|integer|greater_than[0]',
        'member_number'    => 'required|max_length[20]|is_unique[members.member_number,member_id,{member_id}]',
        'first_name'       => 'required|max_length[100]',
        'last_name'        => 'required|max_length[100]',
        'membership_date'  => 'required|valid_date',
        'membership_status'=> 'permit_empty|in_list[Active,Inactive,Suspended]',
    ];

    protected $validationMessages = [
        'user_id' => [
            'required' => 'User ID is required.',
            'integer'  => 'User ID must be a valid integer.',
        ],
        'member_number' => [
            'required'  => 'Member number is required.',
            'is_unique' => 'This member number already exists.',
        ],
        'first_name' => [
            'required' => 'First name is required.',
        ],
        'last_name' => [
            'required' => 'Last name is required.',
        ],
        'membership_date' => [
            'required'   => 'Membership date is required.',
            'valid_date' => 'Please provide a valid membership date.',
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
     * Generate the next sequential member number in the format MEM-XXX.
     * Queries the highest existing numeric suffix and increments by one.
     *
     * @return string e.g. 'MEM-002'
     */
    public function getNextMemberNumber(): string
    {
        $row = $this->db->table($this->table)
                        ->select("MAX(CAST(SUBSTRING(member_number, 5) AS UNSIGNED)) AS max_num")
                        ->get()
                        ->getRow();

        $next = ($row && $row->max_num !== null) ? (int) $row->max_num + 1 : 1;

        return 'MEM-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Retrieve a member along with the linked user record.
     *
     * @param  int $member_id
     * @return array|null
     */
    public function getMemberWithUser(int $member_id): ?array
    {
        return $this->select('members.*, users.username, users.email, users.account_status AS user_status, roles.role_name')
                    ->join('users', 'users.user_id = members.user_id', 'left')
                    ->join('roles', 'roles.role_id = users.role_id', 'left')
                    ->where('members.member_id', $member_id)
                    ->first();
    }

    /**
     * Retrieve all active members with their user data.
     *
     * @return array
     */
    public function getActiveMembersWithUsers(): array
    {
        return $this->select('members.*, users.username, users.email')
                    ->join('users', 'users.user_id = members.user_id', 'left')
                    ->where('members.membership_status', 'Active')
                    ->findAll();
    }

    /**
     * Find a member by user_id.
     *
     * @param  int $user_id
     * @return array|null
     */
    public function findByUserId(int $user_id): ?array
    {
        return $this->where('user_id', $user_id)->first();
    }
}
