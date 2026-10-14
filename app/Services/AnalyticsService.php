<?php

namespace App\Services;

/**
 * AnalyticsService — equity-growth snapshots, linear-regression forecasting,
 * and monthly snapshot persistence.
 */
class AnalyticsService
{
    // -------------------------------------------------------------------------
    // Historical data
    // -------------------------------------------------------------------------

    /**
     * Retrieve monthly equity totals for the past N months.
     *
     * Tries analytics_snapshots first (fast path); falls back to aggregating
     * financial_records directly if no snapshot data exists for the period.
     *
     * @param  int   $months  Number of calendar months to look back (default 12)
     * @return array  Ordered oldest-first: [['label' => 'Oct 2026', 'total' => 1500.00], …]
     */
    public function getEquityGrowthData(int $months = 12): array
    {
        $db      = db_connect();
        $result  = [];
        $now     = new \DateTime('now');

        // Build list of month boundaries (oldest → newest)
        $periods = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $dt          = clone $now;
            $dt->modify("-{$i} month");
            $periods[] = [
                'year'  => (int) $dt->format('Y'),
                'month' => (int) $dt->format('n'),
                'label' => $dt->format('M Y'),   // e.g. "Oct 2026"
                'start' => $dt->format('Y-m-01'),
                'end'   => $dt->format('Y-m-t'),
            ];
        }

        // --- Fast path: check analytics_snapshots ----------------------------
        $snapshotMap = [];
        $startDate   = $periods[0]['start'];
        $endDate     = $periods[count($periods) - 1]['end'];

        $snapshotRows = $db->table('analytics_snapshots')
                           ->where('period_type', 'Monthly')
                           ->where('snapshot_date >=', $startDate)
                           ->where('snapshot_date <=', $endDate)
                           ->get()
                           ->getResultArray();

        foreach ($snapshotRows as $snap) {
            $key  = date('Y-n', strtotime($snap['snapshot_date']));
            $data = is_string($snap['totals']) ? json_decode($snap['totals'], true) : $snap['totals'];
            $snapshotMap[$key] = (float) ($data['equity_total'] ?? 0);
        }

        // --- Per-period resolution -------------------------------------------
        foreach ($periods as $period) {
            $key = "{$period['year']}-{$period['month']}";

            if (isset($snapshotMap[$key])) {
                $total = $snapshotMap[$key];
            } else {
                // Compute from financial_records (approved Contributions only)
                $row = $db->table('financial_records')
                          ->selectSum('amount', 'total')
                          ->where('record_type', 'Contribution')
                          ->where('approval_status', 'Approved')
                          ->where('transaction_date >=', $period['start'])
                          ->where('transaction_date <=', $period['end'])
                          ->get()
                          ->getRowArray();

                $total = ($row && $row['total'] !== null) ? (float) $row['total'] : 0.0;
            }

            $result[] = [
                'label' => $period['label'],
                'total' => round($total, 2),
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Forecasting
    // -------------------------------------------------------------------------

    /**
     * Predict future monthly equity totals using ordinary-least-squares linear
     * regression on historical data.
     *
     * NOTE: these are ESTIMATES / PREDICTIONS based on historical trends.
     * Actual results will differ.
     *
     * @param  array $historicalData  Output of getEquityGrowthData() —
     *                                each row has 'label' and 'total'
     * @param  int   $forecastMonths  How many future months to predict (default 3)
     * @return array  [['label' => 'Jan 2027', 'predicted' => 2100.00], …]
     *               Returns empty array when fewer than 2 data points exist.
     */
    public function predictNextMonths(array $historicalData, int $forecastMonths = 3): array
    {
        $n = count($historicalData);

        if ($n < 2) {
            return [];
        }

        // Build x/y series (x = 0-indexed period number, y = total amount)
        $xs = [];
        $ys = [];
        for ($i = 0; $i < $n; $i++) {
            $xs[] = $i;
            $ys[] = (float) $historicalData[$i]['total'];
        }

        // OLS: m = (n·Σxy − Σx·Σy) / (n·Σx² − (Σx)²)
        //      b = (Σy − m·Σx) / n
        $sumX  = array_sum($xs);
        $sumY  = array_sum($ys);
        $sumXY = 0.0;
        $sumXX = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $xs[$i] * $ys[$i];
            $sumXX += $xs[$i] * $xs[$i];
        }

        $denom = ($n * $sumXX) - ($sumX * $sumX);

        if (abs($denom) < 1e-10) {
            // All x values identical — flat line; use the mean as the prediction
            $mean = $sumY / $n;
            $m    = 0.0;
            $b    = $mean;
        } else {
            $m = (($n * $sumXY) - ($sumX * $sumY)) / $denom;
            $b = ($sumY - ($m * $sumX)) / $n;
        }

        // Determine the last historical month so we can label forecast months
        $lastLabel   = end($historicalData)['label'];  // e.g. "Oct 2026"
        $lastMonthDt = \DateTime::createFromFormat('M Y', $lastLabel);

        $forecasts = [];
        for ($f = 1; $f <= $forecastMonths; $f++) {
            $xForecast = $n - 1 + $f;  // continues the index series
            $predicted = round($m * $xForecast + $b, 2);

            // Advance date label
            $dt    = clone $lastMonthDt;
            $dt->modify("+{$f} month");
            $label = $dt->format('M Y');

            $forecasts[] = [
                'label'     => $label,
                'predicted' => max(0.0, $predicted),  // equity cannot be negative
            ];
        }

        return $forecasts;
    }

    // -------------------------------------------------------------------------
    // Snapshot persistence
    // -------------------------------------------------------------------------

    /**
     * Save (or update) a monthly snapshot for the month containing $date.
     *
     * @param  string $date   Any date within the target month (Y-m-d)
     * @param  float  $total  Equity total for that month
     * @return void
     */
    public function saveMonthlySnapshot(string $date, float $total): void
    {
        $db           = db_connect();
        // Normalise to the first day of the month for consistent keying
        $snapshotDate = date('Y-m-01', strtotime($date));
        $totalsJson   = json_encode(['equity_total' => round($total, 2)]);
        $now          = date('Y-m-d H:i:s');

        // Check for existing row
        $existing = $db->table('analytics_snapshots')
                       ->where('period_type', 'Monthly')
                       ->where('snapshot_date', $snapshotDate)
                       ->get()
                       ->getRowArray();

        if ($existing) {
            $db->table('analytics_snapshots')
               ->where('snapshot_id', $existing['snapshot_id'])
               ->update([
                   'totals'     => $totalsJson,
                   'updated_at' => $now,
               ]);
        } else {
            $db->table('analytics_snapshots')
               ->insert([
                   'snapshot_date' => $snapshotDate,
                   'period_type'   => 'Monthly',
                   'totals'        => $totalsJson,
                   'created_at'    => $now,
                   'updated_at'    => $now,
               ]);
        }
    }

    /**
     * Chart.js payload for the equity growth chart plus headline stats.
     */
    public function chartPayload(int $months = 12, int $forecastMonths = 3): array
    {
        $historical = $this->getEquityGrowthData($months);
        $forecast   = $this->predictNextMonths($historical, $forecastMonths);
        $actual     = array_map('floatval', array_column($historical, 'total'));

        $db  = db_connect();
        $sum = fn(array $types) => (float) ($db->table('financial_records')
            ->whereIn('record_type', $types)
            ->where('approval_status', 'Approved')
            ->selectSum('amount')->get()->getRowArray()['amount'] ?? 0);

        return [
            'labels'             => array_column($historical, 'label'),
            'actual'             => $actual,
            'forecast'           => array_map('floatval', array_column($forecast, 'predicted')),
            'forecastLabels'     => array_column($forecast, 'label'),
            'forecastStartIndex' => count($actual),
            'stats'              => [
                'total_members'       => $db->table('members')->countAllResults(),
                'total_contributions' => $sum(['Contribution']),
                'active_loans'        => $db->table('financial_records')
                    ->where('record_type', 'Loan Disbursement')
                    ->where('approval_status', 'Approved')
                    ->countAllResults(),
                'current_equity'      => $sum(['Contribution', 'Dividend']),
            ],
            'note' => 'Forecast values are ESTIMATES / PREDICTIONS based on historical trends.',
        ];
    }
}
