<?php

namespace App\Models;

use CodeIgniter\Model;

class AnalyticsSnapshotModel extends Model
{
    protected $table            = 'analytics_snapshots';
    protected $primaryKey       = 'snapshot_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'snapshot_date',
        'period_type',
        'totals',
    ];

    // Timestamps
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $dateFormat    = 'datetime';

    // Validation
    protected $validationRules = [
        'snapshot_date' => 'required|valid_date',
        'period_type'   => 'required|in_list[Monthly,Quarterly,Annual]',
        'totals'        => 'permit_empty',
    ];

    protected $validationMessages = [
        'snapshot_date' => [
            'required'   => 'Snapshot date is required.',
            'valid_date' => 'Please provide a valid snapshot date.',
        ],
        'period_type' => [
            'required' => 'Period type is required.',
            'in_list'  => 'Period type must be one of: Monthly, Quarterly, Annual.',
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
     * Retrieve the last N months of monthly snapshots, ordered by snapshot_date DESC.
     *
     * @param  int $months  Number of monthly snapshots to retrieve (default 12)
     * @return array
     */
    public function getMonthlyTotals(int $months = 12): array
    {
        return $this->where('period_type', 'Monthly')
                    ->orderBy('snapshot_date', 'DESC')
                    ->limit($months)
                    ->findAll();
    }

    /**
     * Retrieve all quarterly snapshots, ordered by snapshot_date DESC.
     *
     * @return array
     */
    public function getQuarterlyTotals(): array
    {
        return $this->where('period_type', 'Quarterly')
                    ->orderBy('snapshot_date', 'DESC')
                    ->findAll();
    }

    /**
     * Retrieve all annual snapshots, ordered by snapshot_date DESC.
     *
     * @return array
     */
    public function getAnnualTotals(): array
    {
        return $this->where('period_type', 'Annual')
                    ->orderBy('snapshot_date', 'DESC')
                    ->findAll();
    }

    /**
     * Retrieve the most recent snapshot for each period type.
     *
     * @return array  Keyed by period_type
     */
    public function getLatestByPeriodType(): array
    {
        $results = [];

        foreach (['Monthly', 'Quarterly', 'Annual'] as $type) {
            $row = $this->where('period_type', $type)
                        ->orderBy('snapshot_date', 'DESC')
                        ->first();

            if ($row !== null) {
                $results[$type] = $row;
            }
        }

        return $results;
    }

    /**
     * Retrieve all snapshots within a given date range.
     *
     * @param  string $from        Start date (Y-m-d)
     * @param  string $to          End date (Y-m-d)
     * @param  string|null $type   Optional period_type filter
     * @return array
     */
    public function getSnapshotsByDateRange(string $from, string $to, ?string $type = null): array
    {
        $builder = $this->where('snapshot_date >=', $from)
                        ->where('snapshot_date <=', $to);

        if ($type !== null) {
            $builder->where('period_type', $type);
        }

        return $builder->orderBy('snapshot_date', 'ASC')->findAll();
    }
}
