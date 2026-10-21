<?php

namespace App\Libraries;

use App\Models\CooperativeAccountModel;
use App\Services\LoanService;

/**
 * Made-up but realistic cooperative records for demos and the capstone
 * presentation, in the same shape as an uploaded import workbook (see
 * DataImportService::SHEETS). Same seed and day, same data.
 */
class DemoData
{
    public const PASSWORD = 'Member@123';

    private const FIRST = ['Maria', 'Jose', 'Ana', 'Juan', 'Rosa', 'Pedro', 'Carmen', 'Antonio', 'Teresa', 'Ramon', 'Luz', 'Miguel',
        'Elena', 'Manuel', 'Gloria', 'Ricardo', 'Josefina', 'Eduardo', 'Cristina', 'Fernando', 'Lourdes', 'Roberto', 'Angelica',
        'Danilo', 'Marites', 'Rogelio', 'Jocelyn', 'Arnel', 'Rowena', 'Noel', 'Liza', 'Romeo', 'Shiela', 'Benjie', 'Rachelle',
        'Jomar', 'Kristine', 'Paolo', 'Janelle', 'Mark'];
    private const LAST = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada',
        'Castillo', 'Flores', 'Villanueva', 'Ramos', 'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salazar', 'Mercado', 'Dela Cruz',
        'Gonzales', 'Lopez', 'Fernandez', 'Pascual', 'Manalo', 'Soriano', 'Domingo', 'Valdez', 'Guevarra'];

    /**
     * @return array<string, list<array<string, mixed>>> sheet => rows
     */
    public static function build(int $members = 40, int $seed = 2026, ?string $today = null): array
    {
        mt_srand($seed);
        $today   = $today ?? date('Y-m-d');
        $todayTs = strtotime($today);
        $first   = strtotime('2024-01-08');
        $out     = ['Members' => [], 'Accounts' => [], 'Contributions' => [], 'Loans' => [], 'Loan Payments' => []];
        $used    = [];
        $loanSvc = new LoanService();
        $acctNo  = 0;
        $loanNo  = 0;

        for ($i = 1; $i <= $members; $i++) {
            do {
                $fn = self::FIRST[mt_rand(0, count(self::FIRST) - 1)];
                $ln = self::LAST[mt_rand(0, count(self::LAST) - 1)];
            } while (isset($used["$fn $ln"]));
            $used["$fn $ln"] = true;

            // Earlier members joined as Pioneers; joining slows down over time.
            $joinTs = $first + (int) (pow(mt_rand(0, 1000) / 1000, 1.3) * (strtotime('-40 days', $todayTs) - $first));
            $join   = date('Y-m-d', $joinTs);
            $email  = strtolower(str_replace(' ', '', $fn) . '.' . str_replace(' ', '', $ln)) . '@example.com';
            $ref    = sprintf('M%03d', $i);
            $out['Members'][] = [
                'member_ref' => $ref, 'first_name' => $fn, 'last_name' => $ln, 'email' => $email,
                'username' => strstr($email, '@', true), 'password' => self::PASSWORD,
                'membership_date' => $join, 'status' => $i % 23 === 0 ? 'Inactive' : 'Active',
            ];

            $accounts = [[$join, $joinTs < strtotime('2024-10-01') || mt_rand(1, 100) <= 25 ? 'Pioneer' : 'Basic']];
            if (mt_rand(1, 100) <= 15 && $joinTs < strtotime('-8 months', $todayTs)) {
                $secondTs   = strtotime('+' . mt_rand(4, 8) . ' months', $joinTs);
                $accounts[] = [date('Y-m-d', min($secondTs, strtotime('-30 days', $todayTs))), 'Basic'];
            }

            foreach ($accounts as [$start, $type]) {
                $aRef = sprintf('A%03d', ++$acctNo);
                $out['Accounts'][] = ['account_ref' => $aRef, 'member_ref' => $ref, 'account_type' => $type, 'start_date' => $start];

                // Behaviour of this account: most pay every month, some fall behind, a few never finish joining.
                $roll     = mt_rand(1, 100);
                $style    = $roll <= 6 ? 'unfinished' : ($roll <= 18 ? 'behind' : 'regular');
                $startTs  = strtotime($start);
                $paid     = [];   // date => amount, to size loans later

                // Joining: January up to the opening month, in one or two payments.
                $join = CooperativeAccountModel::joinAmount($start);
                $parts = $style === 'unfinished' ? [min(CooperativeAccountModel::CONTRIBUTION_MAX, max(300, round($join / 2 / 300) * 300))]
                    : ($join > CooperativeAccountModel::CONTRIBUTION_MAX ? [CooperativeAccountModel::CONTRIBUTION_MAX, $join - CooperativeAccountModel::CONTRIBUTION_MAX] : [$join]);
                foreach ($parts as $k => $amount) {
                    $d = date('Y-m-d', min($todayTs, strtotime('+' . ($k * mt_rand(5, 20)) . ' days', $startTs)));
                    $out['Contributions'][] = ['account_ref' => $aRef, 'period' => date('Y-m', $startTs), 'amount' => (float) $amount, 'payment_date' => $d];
                    $paid[$d] = ($paid[$d] ?? 0) + $amount;
                }
                if ($style === 'unfinished') {
                    continue;
                }

                // Monthly ₱300 from the month after opening; "behind" accounts stop a few months ago.
                $stopTs = $style === 'behind' ? strtotime('-' . mt_rand(2, 5) . ' months', $todayTs) : $todayTs;
                for ($m = strtotime(date('Y-m-01', strtotime('+1 month', $startTs))); $m <= $stopTs; $m = strtotime('+1 month', $m)) {
                    if (mt_rand(1, 100) <= 4) {
                        continue;   // the odd missed month
                    }
                    $d = date('Y-m-d', min($todayTs, $m + mt_rand(0, 24) * 86400));
                    if ($d < date('Y-m-d', $m)) {
                        continue;
                    }
                    $out['Contributions'][] = ['account_ref' => $aRef, 'period' => date('Y-m', $m), 'amount' => 300.0, 'payment_date' => $d];
                    $paid[$d] = ($paid[$d] ?? 0) + 300;
                }

                // Loans: about half of the paying accounts borrow, after a few months of saving.
                if (mt_rand(1, 100) > 50) {
                    continue;
                }
                $releaseTs = strtotime('+' . mt_rand(3, 9) . ' months', $startTs) + mt_rand(0, 20) * 86400;
                while ($releaseTs < strtotime('-10 days', $todayTs)) {
                    $saved = 0;
                    foreach ($paid as $d => $a) {
                        if ($d <= date('Y-m-d', $releaseTs)) {
                            $saved += $a;
                        }
                    }
                    $limit  = 2 * $saved;
                    $amount = floor(min($limit, mt_rand(4, 30) * 1000) / 500) * 500;
                    if ($amount < 2000) {
                        break;
                    }
                    $term    = [3, 6, 6, 12, 12, 12, 18, 24][mt_rand(0, 7)];
                    $rate    = CooperativeAccountModel::LOAN_INTEREST_RATE[$type];
                    $release = date('Y-m-d', $releaseTs);
                    $lRef    = sprintf('L%03d', ++$loanNo);
                    $out['Loans'][] = [
                        'loan_ref' => $lRef, 'account_ref' => $aRef, 'loan_amount' => (float) $amount, 'term_months' => $term,
                        'application_date' => date('Y-m-d', $releaseTs - mt_rand(2, 10) * 86400), 'release_date' => $release,
                    ];

                    // Pay each month near its due date; some pay late, a few stop paying.
                    $schedule = $loanSvc->calculateInstallments(['loan_amount' => $amount, 'interest_rate' => $rate, 'loan_term_months' => $term, 'release_date' => $release]);
                    $habit    = mt_rand(1, 100);
                    $owedLate = 0.0;
                    $lastPaid = $releaseTs;
                    foreach ($schedule as $n => $inst) {
                        $dueTs = strtotime($inst['due_date']);
                        if ($habit <= 8 && $n >= 2) {
                            break;   // stopped paying
                        }
                        $late = $habit <= 25 && mt_rand(1, 100) <= 30;
                        $payTs = $late ? $dueTs + mt_rand(5, 25) * 86400 : $dueTs - mt_rand(0, 6) * 86400;
                        if ($payTs > $todayTs) {
                            break;
                        }
                        $amountDue = round($inst['amount_due'] + $owedLate, 2);
                        if ($late && $n < count($schedule) - 1 && mt_rand(1, 2) === 1) {
                            // Paid only half on time; the rest rolls into next month.
                            $half = round($amountDue / 2, 2);
                            $out['Loan Payments'][] = ['loan_ref' => $lRef, 'payment_date' => date('Y-m-d', max($payTs, $releaseTs)), 'amount' => $half, 'payment_method' => self::method()];
                            $owedLate = round($amountDue - $half, 2);
                            continue;
                        }
                        $out['Loan Payments'][] = ['loan_ref' => $lRef, 'payment_date' => date('Y-m-d', max($payTs, $releaseTs)), 'amount' => $amountDue, 'payment_method' => self::method()];
                        $owedLate = 0.0;
                        $lastPaid = $payTs;
                    }

                    // A paid-off borrower may borrow again a few months later.
                    $releaseTs = strtotime('+' . ($term + mt_rand(2, 6)) . ' months', $releaseTs);
                    if ($lastPaid < strtotime('-2 months', $releaseTs) || mt_rand(1, 100) > 40) {
                        break;
                    }
                }
            }
        }

        return self::numberRows($out);
    }

    private static function method(): string
    {
        return ['Cash', 'Cash', 'Cash', 'GCash', 'GCash', 'Bank transfer'][mt_rand(0, 5)];
    }

    /** Add the spreadsheet row numbers the importer reports errors against. */
    private static function numberRows(array $data): array
    {
        foreach ($data as $sheet => $rows) {
            foreach ($rows as $i => $row) {
                $data[$sheet][$i]['_row'] = $i + 2;
            }
        }

        return $data;
    }
}
