<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'user_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'username',
        'email',
        'password_hash',
        'role_id',
        'account_status',
    ];

    // Timestamps
    protected $useTimestamps = false;

    // Validation
    protected $validationRules = [
        'username'      => 'required|min_length[3]|max_length[100]|is_unique[users.username,user_id,{user_id}]',
        'email'         => 'required|valid_email|max_length[255]|is_unique[users.email,user_id,{user_id}]',
        'password_hash' => 'required|min_length[1]',
        'role_id'       => 'required|integer|greater_than[0]',
        'account_status'=> 'permit_empty|in_list[Active,Inactive,Suspended]',
    ];

    protected $validationMessages = [
        'username' => [
            'required'   => 'Username is required.',
            'min_length' => 'Username must be at least 3 characters.',
            'max_length' => 'Username cannot exceed 100 characters.',
            'is_unique'  => 'This username is already taken.',
        ],
        'email' => [
            'required'    => 'Email address is required.',
            'valid_email' => 'Please provide a valid email address.',
            'is_unique'   => 'This email address is already registered.',
        ],
        'password_hash' => [
            'required' => 'Password is required.',
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
     * Find a user by their username.
     *
     * @param  string $username
     * @return array|null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Find a user by their email address.
     *
     * @param  string $email
     * @return array|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->first();
    }

    /**
     * Retrieve all active users.
     *
     * @return array
     */
    public function getActiveUsers(): array
    {
        return $this->where('account_status', 'Active')->findAll();
    }

    /**
     * Retrieve users with their role information.
     *
     * @return array
     */
    public function getUsersWithRoles(): array
    {
        return $this->select('users.*, roles.role_name')
                    ->join('roles', 'roles.role_id = users.role_id', 'left')
                    ->findAll();
    }
}
