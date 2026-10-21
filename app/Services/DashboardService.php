<?php

namespace App\Services;

/**
 * Figures for the dashboard. The same numbers are built for a member (their
 * own accounts) and for an admin (every account in the cooperative), so both
 * dashboards read the same way.
 *
 * Equity = approved contributions + dividends received.
 * Net position = equity − unpaid loan balance.
 */
class DashboardService
{
    private const RECORD_TYPES = ['Contribution', 'Loan Disbursement', 'Loan Payment', 'Dividend'];

    /**
     * @param list<int>|null $accountIds Accounts to include; null = the whole cooperative.
     */
    public function build(?array $accountIds): array
    {
        $db = db_connect();

        // A member without accounts sees zeros, not the cooperative's totals.
        $scope = static function ($builder, string $column) use ($accountIds) {
            if ($accountIds !== null) {
                $builder->whereIn($column, $accountIds === [] ? [0] : $accountIds);
            }

            return $builder;
        };

        $contributions = (float) ($scope($db->table('financial_records'), 'account_id')
            ->selectSum('amount')
            ->where('record_type', 'Contribution')
            ->where('approval_status', 'Approved')
            ->get()->getRowArray()['amount'] ?? 0);

        $dividends = (float) ($scope($db->table('dividends'), 'account_id')
            ->selectSum('dividend_amount')
            ->where('dividend_status', 'Distributed')
            ->get()->getRowArray()['dividend_amount'] ?? 0);

        $loans       = $this->activeLoans($scope);
        $loanBalance = array_sum(array_column($loans, 'balance'));
        $equity      = $contributions + $dividends;

        return [
            'equity'        => round($equity, 2),
            'contributions' => round($contributions, 2),
            'dividends'     => round($dividends, 2),
            'loanBalance'   => round($loanBalance, 2),
            'netPosition'   => round($equity - $loanBalance, 2),
            'activeLoans'   => $loans,
            'records'       => $this->recordSummary($scope),
            'recent'        => $this->recentActivity($scope),
            'chart'         => $this->monthlyContributions($scope),
            'dividendPlan'  => (new DividendService())->plan((int) date('Y')),
        ];
    }

    /** Active loans with what is paid, what is left and the next due date. */
    private function activeLoans(callable $scope): array
    {
        $rows = $scope(db_connect()->table('loans l'), 'l.account_id')
            ->select("l.loan_id, l.loan_amount, l.interest_amount, l.loan_term_months, l.approval_date,
                      ca.account_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name,
                      COALESCE(SUM(li.amount_paid), 0) AS paid,
                      MIN(CASE WHEN li.payment_status NOT IN ('Paid', 'Partial') THEN li.due_date END) AS next_due_date")
            ->join('cooperative_accounts ca', 'ca.account_id = l.account_id')
            ->join('members m', 'm.member_id = ca.member_id')
            ->join('loan_installments li', 'li.loan_id = l.loan_id', 'left')
            ->where('l.loan_status', 'Active')
            ->groupBy('l.loan_id, l.loan_amount, l.interest_amount, l.loan_term_months, l.approval_date, ca.account_number, m.first_name, m.last_name')
            ->orderBy('next_due_date', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $loan): array {
            $loan['total']   = round((float) $loan['loan_amount'] + (float) $loan['interest_amount'], 2);
            $loan['paid']    = round((float) $loan['paid'], 2);
            $loan['balance'] = round(max(0, $loan['total'] - $loan['paid']), 2);

            return $loan;
        }, $rows);
    }

    /** Approved total and count, plus pending count, for each record type. */
    private function recordSummary(callable $scope): array
    {
        $rows = $scope(db_connect()->table('financial_records'), 'account_id')
            ->select("record_type,
                      SUM(CASE WHEN approval_status = 'Approved' THEN amount ELSE 0 END) AS approved_amount,
                      SUM(CASE WHEN approval_status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
                      SUM(CASE WHEN approval_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count")
            ->groupBy('record_type')
            ->get()->getResultArray();

        $byType = array_column($rows, null, 'record_type');
        $summary = [];
        foreach (self::RECORD_TYPES as $type) {
            $summary[] = [
                'type'            => $type,
                'approved_amount' => round((float) ($byType[$type]['approved_amount'] ?? 0), 2),
                'approved_count'  => (int) ($byType[$type]['approved_count'] ?? 0),
                'pending_count'   => (int) ($byType[$type]['pending_count'] ?? 0),
            ];
        }

        return $summary;
    }

    /** Latest financial records, newest first. */
    private function recentActivity(callable $scope, int $limit = 8): array
    {
        $rows = $scope(db_connect()->table('financial_records fr'), 'fr.account_id')
            ->select("fr.financial_record_id, fr.record_type, fr.amount, fr.transaction_date, fr.approval_status,
                      fr.first_approved_by, fr.created_at, ca.account_number,
                      CONCAT(m.first_name, ' ', m.last_name) AS member_name")
            ->join('cooperative_accounts ca', 'ca.account_id = fr.account_id', 'left')
            ->join('members m', 'm.member_id = ca.member_id', 'left')
            ->orderBy('fr.created_at', 'DESC')
            ->orderBy('fr.financial_record_id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static function (array $r): array {
            $r['status_label'] = $r['approval_status'];

            return $r;
        }, $rows);
    }

    /** Approved contributions per month they were paid for (last 12 months), oldest first. */
    private function monthlyContributions(callable $scope): array
    {
        $rows = $scope(db_connect()->table('contributions'), 'account_id')
            ->select('contribution_period AS ym, SUM(amount) AS total')
            ->where('payment_status', 'Approved')
            ->where('contribution_period >=', date('Y-m', strtotime('first day of -11 months')))
            ->where('contribution_period <=', date('Y-m'))
            ->groupBy('contribution_period')
            ->get()->getResultArray();

        $totals = array_column($rows, 'total', 'ym');
        $labels = $values = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts       = strtotime("first day of -{$i} months");
            $labels[] = date('M Y', $ts);
            $values[] = round((float) ($totals[date('Y-m', $ts)] ?? 0), 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
