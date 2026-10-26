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
     * The cooperative's equity at the end of each of the past N months: every
     * approved contribution and dividend up to that day, the same figure as
     * the "Current Equity" tile, so the line grows as the cooperative does.
     *
     * Read straight from financial_records. analytics_snapshots is not used:
     * nothing keeps it up to date, and the seeded sample row would otherwise
     * pin January 2026 at zero.
     *
     * @param  int   $months  Number of calendar months to look back (default 12)
     * @return array  Ordered oldest-first: [['label' => 'Oct 2026', 'total' => 1500.00], …]
     */
    public function getEquityGrowthData(int $months = 12, ?int $now = null): array
    {
        $db      = db_connect();
        $result  = [];
        // From the 1st, so "-1 month" on the 31st cannot land in the same month again.
        $now     = new \DateTime(date('Y-m-01', $now ?? time()));

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

        $equity = fn () => $db->table('financial_records')
            ->whereIn('record_type', ['Contribution', 'Dividend'])
            ->where('approval_status', 'Approved');

        // Everything before the first month, then each month's additions: two queries in all.
        $total = (float) ($equity()->selectSum('amount', 'total')
            ->where('transaction_date <', $periods[0]['start'])
            ->get()->getRowArray()['total'] ?? 0);

        $added = [];
        foreach ($equity()->select("DATE_FORMAT(transaction_date, '%Y-%m') AS ym, SUM(amount) AS total", false)
                     ->where('transaction_date >=', $periods[0]['start'])
                     ->where('transaction_date <=', $periods[count($periods) - 1]['end'])
                     ->groupBy('ym')->get()->getResultArray() as $row) {
            $added[$row['ym']] = (float) $row['total'];
        }

        foreach ($periods as $period) {
            $total += $added[substr($period['start'], 0, 7)] ?? 0.0;
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
        $lastMonthDt = \DateTime::createFromFormat('!M Y', $lastLabel);   // '!' = the 1st, not today's day

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
    public function chartPayload(int $months = 12, int $forecastMonths = 3, ?int $now = null): array
    {
        // One query per month, so keep the range sensible whatever the URL asks for.
        $months         = max(1, min(60, $months));
        $forecastMonths = max(0, min(12, $forecastMonths));

        $historical = $this->getEquityGrowthData($months, $now);
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
                // Loans still being repaid, not every loan ever released.
                'active_loans'        => $db->table('loans')
                    ->whereIn('loan_status', ['Approved', 'Active'])
                    ->countAllResults(),
                'current_equity'      => $sum(['Contribution', 'Dividend']),
            ],
            'note' => 'Forecast values are ESTIMATES / PREDICTIONS based on historical trends.',
        ];
    }

    // -------------------------------------------------------------------------
    // Recent activity for a time range
    // -------------------------------------------------------------------------

    /**
     * Time ranges: [label, length in seconds, bucket size in seconds, default refresh in seconds].
     * Every range is a rolling window ending now. "weekly" is today and the 6
     * days before (7 calendar days), "monthly" today and the 29 days before.
     */
    public const RANGES = [
        '30s'     => ['Last 30 seconds', 30, 5, 10],
        '1m'      => ['Last 1 minute', 60, 10, 10],
        '2m'      => ['Last 2 minutes', 120, 15, 15],
        '5m'      => ['Last 5 minutes', 300, 30, 30],
        '10m'     => ['Last 10 minutes', 600, 60, 30],
        '30m'     => ['Last 30 minutes', 1800, 180, 60],
        '1h'      => ['Last 1 hour', 3600, 300, 60],
        '5h'      => ['Last 5 hours', 18000, 1800, 300],
        '12h'     => ['Last 12 hours', 43200, 3600, 300],
        '24h'     => ['Last 24 hours', 86400, 3600, 300],
        'weekly'  => ['Weekly (last 7 days)', 7, 86400, 900],
        'monthly' => ['Monthly (last 30 days)', 30, 86400, 900],
    ];

    /** Money that moved in a time range, grouped into buckets, from approved records. */
    public function activity(string $range, ?int $now = null): array
    {
        $now = $now ?? time();
        if (! isset(self::RANGES[$range])) {
            $range = '24h';
        }
        [$label, $length, $bucket, $refresh] = self::RANGES[$range];

        if ($bucket === 86400) {
            // Whole calendar days: today and the ($length - 1) days before it.
            $from = strtotime('today', $now) - ($length - 1) * 86400;
            $from = strtotime(date('Y-m-d', $from));
        } else {
            $from = $now - $length;
        }

        $series = ['Contribution' => [], 'Loan Payment' => [], 'Loan Disbursement' => [], 'Dividend' => []];
        $labels = [];
        $starts = [];
        for ($t = $from; $t < $now; $t = $bucket === 86400 ? strtotime('+1 day', $t) : $t + $bucket) {
            $starts[] = $t;
            $labels[] = $bucket === 86400 ? date('M j', $t) : ($bucket < 60 ? date('g:i:s A', $t) : ($length > 43200 ? date('M j g A', $t) : date('g:i A', $t)));
            foreach ($series as $type => $_) {
                $series[$type][] = 0.0;
            }
        }

        $db       = db_connect();
        $approved = 'COALESCE(second_approval_date, first_approval_date, updated_at)';
        $rows     = $db->table('financial_records')
            ->select("record_type, amount, {$approved} AS approved_at", false)
            ->where('approval_status', 'Approved')
            ->where("{$approved} >= " . $db->escape(date('Y-m-d H:i:s', $from)), null, false)
            ->where("{$approved} <= " . $db->escape(date('Y-m-d H:i:s', $now)), null, false)
            ->get()->getResultArray();

        $totals = [];
        foreach ($series as $type => $_) {
            $totals[$type] = ['amount' => 0.0, 'count' => 0];
        }
        foreach ($rows as $row) {
            $t = strtotime($row['approved_at']);
            $i = count($starts) - 1;
            while ($i > 0 && $starts[$i] > $t) {
                $i--;
            }
            $type = $row['record_type'];
            if (! isset($series[$type])) {
                continue;
            }
            $series[$type][$i]       = round($series[$type][$i] + (float) $row['amount'], 2);
            $totals[$type]['amount'] = round($totals[$type]['amount'] + (float) $row['amount'], 2);
            $totals[$type]['count']++;
        }

        $waiting = db_connect()->table('financial_records')
            ->where('approval_status', 'Pending')
            ->where('created_at >=', date('Y-m-d H:i:s', $from))
            ->countAllResults();

        return [
            'range'           => $range,
            'label'           => $label,
            'from'            => date('M j, Y g:i:s A', $from),
            'to'              => date('M j, Y g:i:s A', $now),
            'generated_at'    => date('g:i:s A', $now),
            'bucket_seconds'  => $bucket,
            'default_refresh' => $refresh,
            'labels'          => $labels,
            'series'          => [
                'contributions' => $series['Contribution'],
                'payments'      => $series['Loan Payment'],
                'loans'         => $series['Loan Disbursement'],
                'dividends'     => $series['Dividend'],
            ],
            'totals'          => [
                'contributions' => $totals['Contribution'],
                'payments'      => $totals['Loan Payment'],
                'loans'         => $totals['Loan Disbursement'],
                'dividends'     => $totals['Dividend'],
            ],
            'waiting'         => $waiting,
            'empty'           => $rows === [],
        ];
    }
}
