<?php

namespace App\Services;

use App\Models\CooperativeAccountModel;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Bulk import of a cooperative's existing records from an Excel workbook:
 * members (with logins), accounts, contribution history, released loans and
 * their payments. Everything is validated first and then written in one
 * transaction, as already-approved records chained into the ledger.
 *
 * Rows are linked by the client's own references (member_ref, account_ref,
 * loan_ref), which only need to be unique within the workbook.
 */
class DataImportService
{
    /** Sheet => [column => help text]. Columns marked * are required. */
    public const SHEETS = [
        'Members' => [
            'member_ref*'      => 'Your own ID for this member (any text, unique in this file). Used to link accounts.',
            'first_name*'      => 'First name.',
            'last_name*'       => 'Last name.',
            'email*'           => 'Email address (unique).',
            'username'         => 'Login name. Blank: made from the email address.',
            'password'         => 'Initial password, at least 8 characters. Blank: one is generated and shown after the import.',
            'membership_date*' => 'Date the person became a member.',
            'status'           => 'Active, Inactive or Suspended. Blank: Active.',
            'member_number'    => 'Member number to keep (e.g. MEM-014). Blank: assigned automatically.',
        ],
        'Accounts' => [
            'account_ref*'    => 'Your own ID for this account (unique in this file). Used to link contributions and loans.',
            'member_ref*'     => 'member_ref of the owner on the Members sheet.',
            'account_type*'   => 'Basic or Pioneer.',
            'start_date*'     => 'Date the account was opened.',
            'status'          => 'Leave blank (Active/Pending follows the contributions), or Inactive / Closed.',
            'account_number'  => 'Account number to keep (e.g. ACC-2024-007). Blank: assigned automatically.',
        ],
        'Contributions' => [
            'account_ref*'  => 'account_ref on the Accounts sheet.',
            'period*'       => 'Month the payment is for, as YYYY-MM (e.g. 2025-03).',
            'amount*'       => 'Amount paid, PHP 300 to PHP 3,600.',
            'payment_date*' => 'Date it was paid.',
        ],
        'Loans' => [
            'loan_ref*'         => 'Your own ID for this loan (unique in this file). Used to link payments.',
            'account_ref*'      => 'account_ref on the Accounts sheet.',
            'loan_amount*'      => 'Principal released.',
            'term_months*'      => '3, 6, 12, 18 or 24.',
            'application_date*' => 'Date the member applied.',
            'release_date*'     => 'Date the money was released. The monthly schedule starts from here.',
            'interest_rate'     => 'Percent, e.g. 5. Blank: 2 for Pioneer, 5 for Basic accounts.',
        ],
        'Loan Payments' => [
            'loan_ref*'      => 'loan_ref on the Loans sheet.',
            'payment_date*'  => 'Date it was paid.',
            'amount*'        => 'Amount paid. Applied to the oldest unpaid month first.',
            'payment_method' => 'Cash, GCash, Bank transfer, … Blank: Cash.',
        ],
    ];

    public const LOAN_TERMS = [3, 6, 12, 18, 24];

    private const MAX_ROWS = 20000;

    /** @var list<array{sheet: string, row: int, column: string, message: string}> */
    private array $errors = [];

    /** @var list<array{sheet: string, row: int, column: string, message: string}> */
    private array $warnings = [];

    // ------------------------------------------------------------------ read

    /**
     * Read the workbook into sheet => list of rows (column => value, plus
     * '_row' with the spreadsheet row number). Unknown sheets are ignored.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function read(string $path): array
    {
        $wanted = [];
        foreach (array_keys(self::SHEETS) as $name) {
            $wanted[self::key($name)] = $name;
        }

        $reader = new Reader();
        $reader->open($path);
        $data  = array_fill_keys(array_keys(self::SHEETS), []);
        $total = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $name = $wanted[self::key($sheet->getName())] ?? null;
                if ($name === null) {
                    continue;
                }
                $headers = null;
                foreach ($sheet->getRowIterator() as $n => $row) {
                    $cells = $row->toArray();
                    if ($headers === null) {
                        $headers = array_map(fn($h) => self::key(rtrim((string) $h, '*')), $cells);
                        continue;
                    }
                    if (implode('', array_map(fn($v) => $v instanceof DateTimeInterface ? 'x' : trim((string) $v), $cells)) === '') {
                        continue;
                    }
                    if (++$total > self::MAX_ROWS) {
                        throw new \RuntimeException('The file has more than ' . self::MAX_ROWS . ' rows. Split it into smaller files.');
                    }
                    $record = ['_row' => $n];
                    foreach ($headers as $i => $h) {
                        if ($h !== '') {
                            $record[$h] = $cells[$i] ?? null;
                        }
                    }
                    $data[$name][] = $record;
                }
            }
        } finally {
            $reader->close();
        }

        return $data;
    }

    // -------------------------------------------------------------- validate

    /**
     * Check every row against the app's rules and the existing database.
     *
     * @param  array<string, list<array<string, mixed>>> $data from read()
     * @return array{errors: list<array>, warnings: list<array>, plan: array, counts: array<string, int>}
     */
    public function validate(array $data): array
    {
        $this->errors = $this->warnings = [];
        foreach (array_keys(self::SHEETS) as $name) {
            $data[$name] = array_map(fn($r) => ['_sheet' => $name] + $r + ['_row' => 0], $data[$name] ?? []);
        }
        $db    = db_connect();
        $today = date('Y-m-d');

        $plan = ['members' => [], 'accounts' => [], 'contributions' => [], 'loans' => [], 'payments' => []];

        if (array_sum(array_map('count', $data)) === 0) {
            $this->error('Members', 0, '', 'The file has no data rows. Use the template and fill in at least the Members sheet.');
        }

        // Members -------------------------------------------------------------
        $emails = $usernames = $numbers = [];
        foreach ($data['Members'] as $r) {
            $row = (int) $r['_row'];
            $m   = [
                'ref'        => $this->text($r, 'member_ref'),
                'first_name' => $this->text($r, 'first_name'),
                'last_name'  => $this->text($r, 'last_name'),
                'email'      => strtolower($this->text($r, 'email')),
                'username'   => $this->text($r, 'username'),
                'password'   => $this->text($r, 'password'),
                'date'       => $this->date($r, 'membership_date'),
                'status'     => $this->choice($r, 'status', ['Active', 'Inactive', 'Suspended'], 'Active'),
                'number'     => strtoupper($this->text($r, 'member_number')),
                'row'        => $row,
            ];
            foreach (['ref' => 'member_ref', 'first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email'] as $k => $col) {
                if ($m[$k] === '') {
                    $this->error('Members', $row, $col, 'Required.');
                }
            }
            if ($m['ref'] !== '' && isset($plan['members'][$m['ref']])) {
                $this->error('Members', $row, 'member_ref', "member_ref \"{$m['ref']}\" is used twice.");
            }
            if ($m['email'] !== '' && ! filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
                $this->error('Members', $row, 'email', 'Not a valid email address.');
            }
            if ($m['username'] === '' && $m['email'] !== '') {
                $m['username'] = preg_replace('/[^a-z0-9._]/', '', strstr($m['email'], '@', true) ?: $m['email']);
            }
            if ($m['username'] !== '' && ! preg_match('/^[A-Za-z0-9._-]{3,100}$/', $m['username'])) {
                $this->error('Members', $row, 'username', 'Use 3 to 100 letters, numbers, dots, dashes or underscores.');
            }
            if ($m['password'] !== '' && strlen($m['password']) < 8) {
                $this->error('Members', $row, 'password', 'Must be at least 8 characters (or leave blank to generate one).');
            }
            if ($m['date'] !== null && $m['date'] > $today) {
                $this->error('Members', $row, 'membership_date', 'Cannot be in the future.');
            }
            if ($m['number'] !== '' && ! preg_match('/^[A-Z0-9-]{1,20}$/', $m['number'])) {
                $this->error('Members', $row, 'member_number', 'Use up to 20 letters, numbers or dashes.');
            }
            foreach ([['email', $m['email'], &$emails], ['username', strtolower($m['username']), &$usernames], ['member_number', $m['number'], &$numbers]] as [$col, $value, &$seen]) {
                if ($value === '') {
                    continue;
                }
                if (isset($seen[$value])) {
                    $this->error('Members', $row, $col, "\"{$value}\" is also on row {$seen[$value]}.");
                }
                $seen[$value] = $row;
            }
            unset($seen);
            if ($m['ref'] !== '') {
                $plan['members'][$m['ref']] = $m;
            }
        }
        $this->taken($db, 'users', 'email', $emails, 'Members', 'email', 'already has a login in the system');
        $this->taken($db, 'users', 'username', $usernames, 'Members', 'username', 'is already taken in the system');
        $this->taken($db, 'members', 'member_number', $numbers, 'Members', 'member_number', 'already exists in the system');

        // Accounts ------------------------------------------------------------
        $accountNumbers = [];
        foreach ($data['Accounts'] as $r) {
            $row = (int) $r['_row'];
            $a   = [
                'ref'       => $this->text($r, 'account_ref'),
                'member'    => $this->text($r, 'member_ref'),
                'type'      => $this->choice($r, 'account_type', CooperativeAccountModel::ACCOUNT_TYPES, null, true),
                'start'     => $this->date($r, 'start_date'),
                'status'    => $this->choice($r, 'status', ['Inactive', 'Closed'], null),
                'number'    => strtoupper($this->text($r, 'account_number')),
                'row'       => $row,
            ];
            if ($a['ref'] === '') {
                $this->error('Accounts', $row, 'account_ref', 'Required.');
            } elseif (isset($plan['accounts'][$a['ref']])) {
                $this->error('Accounts', $row, 'account_ref', "account_ref \"{$a['ref']}\" is used twice.");
            }
            if ($a['member'] === '') {
                $this->error('Accounts', $row, 'member_ref', 'Required.');
            } elseif (! isset($plan['members'][$a['member']])) {
                $this->error('Accounts', $row, 'member_ref', "No member with member_ref \"{$a['member']}\" on the Members sheet.");
            } elseif ($a['start'] && ($joined = $plan['members'][$a['member']]['date']) && $a['start'] < $joined) {
                $this->warning('Accounts', $row, 'start_date', "Opened before the member joined ({$joined}).");
            }
            if ($a['start'] !== null && $a['start'] > $today) {
                $this->error('Accounts', $row, 'start_date', 'Cannot be in the future.');
            }
            if ($a['number'] !== '') {
                if (! preg_match('/^[A-Z0-9-]{1,30}$/', $a['number'])) {
                    $this->error('Accounts', $row, 'account_number', 'Use up to 30 letters, numbers or dashes.');
                } elseif (isset($accountNumbers[$a['number']])) {
                    $this->error('Accounts', $row, 'account_number', "\"{$a['number']}\" is also on row {$accountNumbers[$a['number']]}.");
                }
                $accountNumbers[$a['number']] = $row;
            }
            if ($a['ref'] !== '' && ! isset($plan['accounts'][$a['ref']])) {
                $plan['accounts'][$a['ref']] = $a + ['paid' => 0.0];
            }
        }
        $this->taken($db, 'cooperative_accounts', 'account_number', $accountNumbers, 'Accounts', 'account_number', 'already exists in the system');
        foreach ($plan['members'] as $ref => $m) {
            if (! in_array($ref, array_column($plan['accounts'], 'member'), true)) {
                $this->warning('Members', $m['row'], 'member_ref', 'This member has no account on the Accounts sheet.');
            }
        }

        // Contributions -------------------------------------------------------
        foreach ($data['Contributions'] as $r) {
            $row    = (int) $r['_row'];
            $ref    = $this->text($r, 'account_ref');
            $period = $this->period($r, 'period');
            $amount = $this->money($r, 'amount');
            $date   = $this->date($r, 'payment_date');
            $acct   = $plan['accounts'][$ref] ?? null;

            if ($ref === '') {
                $this->error('Contributions', $row, 'account_ref', 'Required.');
            } elseif (! $acct) {
                $this->error('Contributions', $row, 'account_ref', "No account with account_ref \"{$ref}\" on the Accounts sheet.");
            }
            if ($amount !== null && ($amount < CooperativeAccountModel::CONTRIBUTION_MIN || $amount > CooperativeAccountModel::CONTRIBUTION_MAX)) {
                $this->error('Contributions', $row, 'amount', 'Must be between PHP ' . number_format(CooperativeAccountModel::CONTRIBUTION_MIN, 2)
                    . ' and PHP ' . number_format(CooperativeAccountModel::CONTRIBUTION_MAX, 2) . '.');
            }
            if ($date !== null && $date > $today) {
                $this->error('Contributions', $row, 'payment_date', 'Cannot be in the future.');
            }
            if ($acct && $date && $acct['start'] && $date < $acct['start']) {
                $this->warning('Contributions', $row, 'payment_date', "Paid before the account was opened ({$acct['start']}).");
            }
            if ($acct && $period && $amount !== null && $date) {
                $plan['contributions'][] = ['account' => $ref, 'period' => $period, 'amount' => $amount, 'date' => $date, 'row' => $row];
                $plan['accounts'][$ref]['paid'] += $amount;
            }
        }

        // Loans ---------------------------------------------------------------
        foreach ($data['Loans'] as $r) {
            $row  = (int) $r['_row'];
            $l    = [
                'ref'     => $this->text($r, 'loan_ref'),
                'account' => $this->text($r, 'account_ref'),
                'amount'  => $this->money($r, 'loan_amount'),
                'term'    => $this->int($r, 'term_months'),
                'applied' => $this->date($r, 'application_date'),
                'release' => $this->date($r, 'release_date'),
                'rate'    => $this->money($r, 'interest_rate', false),
                'row'     => $row,
            ];
            $acct = $plan['accounts'][$l['account']] ?? null;
            if ($l['ref'] === '') {
                $this->error('Loans', $row, 'loan_ref', 'Required.');
            } elseif (isset($plan['loans'][$l['ref']])) {
                $this->error('Loans', $row, 'loan_ref', "loan_ref \"{$l['ref']}\" is used twice.");
            }
            if ($l['account'] === '') {
                $this->error('Loans', $row, 'account_ref', 'Required.');
            } elseif (! $acct) {
                $this->error('Loans', $row, 'account_ref', "No account with account_ref \"{$l['account']}\" on the Accounts sheet.");
            }
            if ($l['amount'] !== null && $l['amount'] <= 0) {
                $this->error('Loans', $row, 'loan_amount', 'Must be more than zero.');
            }
            if ($l['term'] !== null && ! in_array($l['term'], self::LOAN_TERMS, true)) {
                $this->error('Loans', $row, 'term_months', 'Must be one of ' . implode(', ', self::LOAN_TERMS) . '.');
            }
            if ($l['rate'] !== null && ($l['rate'] < 0 || $l['rate'] > 100)) {
                $this->error('Loans', $row, 'interest_rate', 'Must be a percentage from 0 to 100.');
            }
            if ($l['release'] !== null && $l['release'] > $today) {
                $this->error('Loans', $row, 'release_date', 'Only released loans can be imported; this date is in the future.');
            }
            if ($l['applied'] && $l['release'] && $l['applied'] > $l['release']) {
                $this->error('Loans', $row, 'application_date', 'Must be on or before the release date.');
            }
            if ($acct && $l['amount'] && $l['amount'] > 2 * $acct['paid']) {
                $this->warning('Loans', $row, 'loan_amount', 'More than twice the contributions in this file for the account (the usual loan limit).');
            }
            if ($acct && $l['rate'] === null) {
                $l['rate'] = CooperativeAccountModel::LOAN_INTEREST_RATE[$acct['type'] ?? 'Basic'] ?? 5.00;
            }
            if ($l['ref'] !== '' && ! isset($plan['loans'][$l['ref']]) && $acct && $l['amount'] && $l['term'] && $l['applied'] && $l['release'] && $l['rate'] !== null) {
                $l['payable'] = round($l['amount'] + round($l['amount'] * $l['rate'] / 100, 2), 2);
                $l['paid']    = 0.0;
                $plan['loans'][$l['ref']] = $l;
            }
        }

        // Loan payments -------------------------------------------------------
        foreach ($data['Loan Payments'] as $r) {
            $row    = (int) $r['_row'];
            $ref    = $this->text($r, 'loan_ref');
            $date   = $this->date($r, 'payment_date');
            $amount = $this->money($r, 'amount');
            $method = $this->text($r, 'payment_method') ?: 'Cash';
            $loan   = $plan['loans'][$ref] ?? null;

            if ($ref === '') {
                $this->error('Loan Payments', $row, 'loan_ref', 'Required.');
            } elseif (! $loan) {
                $this->error('Loan Payments', $row, 'loan_ref', "No valid loan with loan_ref \"{$ref}\" on the Loans sheet.");
            }
            if ($amount !== null && $amount <= 0) {
                $this->error('Loan Payments', $row, 'amount', 'Must be more than zero.');
            }
            if ($date !== null && $date > $today) {
                $this->error('Loan Payments', $row, 'payment_date', 'Cannot be in the future.');
            }
            if ($loan && $date && $date < $loan['release']) {
                $this->error('Loan Payments', $row, 'payment_date', "Before the loan was released ({$loan['release']}).");
            }
            if (mb_strlen($method) > 30) {
                $this->error('Loan Payments', $row, 'payment_method', 'At most 30 characters.');
            }
            if ($loan && $date && $amount) {
                $plan['loans'][$ref]['paid'] = round($plan['loans'][$ref]['paid'] + $amount, 2);
                if ($plan['loans'][$ref]['paid'] > $loan['payable'] + 0.004) {
                    $this->error('Loan Payments', $row, 'amount', "Payments for {$ref} add up to more than the loan's total of PHP "
                        . number_format($loan['payable'], 2) . ' (principal plus interest).');
                }
                $plan['payments'][] = ['loan' => $ref, 'date' => $date, 'amount' => $amount, 'method' => $method, 'row' => $row];
            }
        }

        return [
            'errors'   => $this->errors,
            'warnings' => $this->warnings,
            'plan'     => $plan,
            'counts'   => [
                'Members'       => count($plan['members']),
                'Accounts'      => count($plan['accounts']),
                'Contributions' => count($plan['contributions']),
                'Loans'         => count($plan['loans']),
                'Loan Payments' => count($plan['payments']),
            ],
        ];
    }

    // ---------------------------------------------------------------- import

    /**
     * Write a validated plan. All or nothing.
     *
     * @return array{counts: array<string, int>, passwords: list<array{member: string, username: string, password: string}>, ledger: int}
     */
    public function import(array $plan, int $adminId): array
    {
        $db    = db_connect();
        $now   = date('Y-m-d H:i:s');
        $loans = new LoanService();

        $roleId = (int) ($db->table('roles')->where('role_name', 'Member')->get()->getRow('role_id') ?? 2);

        $db->transBegin();
        try {
            // Members and their logins.
            $nextMember = $this->nextNumber($db, 'members', 'member_number', 'MEM-');
            $memberIds  = [];
            $passwords  = [];
            foreach ($plan['members'] as $ref => $m) {
                $password = $m['password'] !== '' ? $m['password'] : self::generatePassword();
                $db->table('users')->insert([
                    'role_id'        => $roleId,
                    'username'       => $m['username'],
                    'email'          => $m['email'],
                    'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
                    'account_status' => $m['status'],
                ]);
                $userId = (int) $db->insertID();
                $number = $m['number'] !== '' ? $m['number'] : 'MEM-' . str_pad((string) $nextMember++, 3, '0', STR_PAD_LEFT);
                $db->table('members')->insert([
                    'user_id'           => $userId,
                    'member_number'     => $number,
                    'first_name'        => $m['first_name'],
                    'last_name'         => $m['last_name'],
                    'membership_date'   => $m['date'],
                    'membership_status' => $m['status'],
                ]);
                $memberIds[$ref] = (int) $db->insertID();
                if ($m['password'] === '') {
                    $passwords[] = ['member' => "{$m['first_name']} {$m['last_name']} ({$number})", 'username' => $m['username'], 'password' => $password];
                }
            }

            // Accounts, numbered per opening year when no number is given.
            $accountIds = [];
            $nextByYear = [];
            foreach ($plan['accounts'] as $ref => $a) {
                $year = substr($a['start'], 0, 4);
                if ($a['number'] === '') {
                    $nextByYear[$year] ??= $this->nextNumber($db, 'cooperative_accounts', 'account_number', "ACC-{$year}-");
                    $a['number'] = "ACC-{$year}-" . str_pad((string) $nextByYear[$year]++, 3, '0', STR_PAD_LEFT);
                }
                $db->table('cooperative_accounts')->insert([
                    'member_id'            => $memberIds[$a['member']],
                    'account_number'       => $a['number'],
                    'account_type'         => $a['type'],
                    'account_start_date'   => $a['start'],
                    'monthly_contribution' => CooperativeAccountModel::MONTHLY_CONTRIBUTION,
                    'account_status'       => 'Pending',
                ]);
                $accountIds[$ref] = (int) $db->insertID();
            }

            // Every money movement, oldest first, so record ids follow the dates.
            $events = [];
            foreach ($plan['contributions'] as $c) {
                $events[] = ['kind' => 'contribution', 'date' => $c['date'], 'sort' => 0] + $c;
            }
            foreach ($plan['loans'] as $l) {
                $events[] = ['kind' => 'loan', 'date' => $l['release'], 'sort' => 1] + $l;
            }
            foreach ($plan['payments'] as $p) {
                $events[] = ['kind' => 'payment', 'sort' => 2] + $p;
            }
            usort($events, fn($x, $y) => [$x['date'], $x['sort'], $x['row']] <=> [$y['date'], $y['sort'], $y['row']]);

            $loanIds    = [];
            $records    = 0;
            $firstFrId  = null;
            foreach ($events as $e) {
                $accountId = $e['kind'] === 'payment' ? $accountIds[$plan['loans'][$e['loan']]['account']] : $accountIds[$e['account']];
                $type      = ['contribution' => 'Contribution', 'loan' => 'Loan Disbursement', 'payment' => 'Loan Payment'][$e['kind']];

                $fr = [
                    'account_id'          => $accountId,
                    'record_type'         => $type,
                    'transaction_date'    => $e['date'],
                    'amount'              => $e['amount'],
                    'approval_status'     => 'Approved',
                    'processed_by'        => $adminId,
                    'first_approved_by'   => $adminId,
                    // Approved on the day it happened, so activity charts show the real history.
                    'first_approval_date' => $e['date'] . ' 12:00:00',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ];

                if ($e['kind'] === 'contribution') {
                    $db->table('financial_records')->insert($fr);
                    $frId = (int) $db->insertID();
                    $db->table('contributions')->insert([
                        'account_id'          => $accountId,
                        'contribution_period' => $e['period'],
                        'amount'              => $e['amount'],
                        'payment_date'        => $e['date'],
                        'payment_status'      => 'Approved',
                        'financial_record_id' => $frId,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                } elseif ($e['kind'] === 'loan') {
                    $interest = round($e['amount'] * $e['rate'] / 100, 2);
                    $db->table('loans')->insert([
                        'account_id'          => $accountId,
                        'total_contributions' => 0,
                        'maximum_loan_limit'  => 0,
                        'loan_amount'         => $e['amount'],
                        'interest_rate'       => $e['rate'],
                        'interest_amount'     => $interest,
                        'loan_term_months'    => $e['term'],
                        'application_date'    => $e['applied'],
                        'approval_date'       => $e['release'],
                        'release_date'        => $e['release'],
                        'loan_status'         => 'Active',
                        'approved_by'         => $adminId,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                    $loanId = (int) $db->insertID();
                    $loanIds[$e['ref']] = $loanId;
                    $rows = array_map(fn($i) => $i + ['loan_id' => $loanId, 'amount_paid' => 0.00, 'payment_status' => 'Upcoming', 'created_at' => $now, 'updated_at' => $now],
                        $loans->calculateInstallments(['loan_amount' => $e['amount'], 'interest_rate' => $e['rate'], 'loan_term_months' => $e['term'], 'release_date' => $e['release']]));
                    $db->table('loan_installments')->insertBatch($rows);
                    $fr['reference_id'] = $loanId;
                    $db->table('financial_records')->insert($fr);
                    $frId = (int) $db->insertID();
                } else {
                    $loanId = $loanIds[$e['loan']];
                    $fr['reference_id'] = $loanId;
                    $db->table('financial_records')->insert($fr);
                    $frId = (int) $db->insertID();
                    $db->table('loan_payments')->insert([
                        'loan_id'             => $loanId,
                        'payment_date'        => $e['date'],
                        'amount_paid'         => $e['amount'],
                        'payment_method'      => $e['method'],
                        'principal_amount'    => $e['amount'],
                        'payment_status'      => 'Approved',
                        'financial_record_id' => $frId,
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                    $paymentId = (int) $db->insertID();

                    // Months unpaid by this date close first, as they would have at the time.
                    $loans->rollOver($loanId, $e['date']);
                    $split = $this->allocate($db, $loanId, $e['amount']);
                    foreach ($split as $installmentId => $applied) {
                        $db->table('loan_payment_installments')->insert(['payment_id' => $paymentId, 'installment_id' => $installmentId, 'amount_applied' => $applied]);
                    }
                    $loans->applyPayment($loanId, $split, $e['date'], $e['date']);
                }
                $firstFrId ??= $frId;
                $records++;
            }

            // Bring every imported loan up to today, then the account statuses.
            foreach ($loanIds as $loanId) {
                $loans->rollOver($loanId);
            }
            model(CooperativeAccountModel::class)->syncStatuses();
            foreach ($plan['accounts'] as $ref => $a) {
                if ($a['status'] !== null) {
                    $db->table('cooperative_accounts')->where('account_id', $accountIds[$ref])->update(['account_status' => $a['status']]);
                }
            }

            // Chain the new records into the ledger in date order.
            $ledger = 0;
            if ($firstFrId !== null) {
                $new = $db->table('financial_records')->where('financial_record_id >=', $firstFrId)
                          ->whereIn('account_id', array_values($accountIds))->orderBy('financial_record_id', 'ASC')->get()->getResultArray();
                $ledger = (new LedgerService())->appendMany($new);
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('The database refused part of the import.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        return [
            'counts' => [
                'Members'       => count($memberIds),
                'Accounts'      => count($accountIds),
                'Contributions' => count($plan['contributions']),
                'Loans'         => count($loanIds),
                'Loan Payments' => count($plan['payments']),
            ],
            'passwords' => $passwords,
            'ledger'    => $ledger,
        ];
    }

    /**
     * Spread an amount over the loan's open months, oldest first.
     *
     * @return array<int, float> installment_id => amount
     */
    private function allocate($db, int $loanId, float $amount): array
    {
        $split = [];
        $left  = round($amount, 2);
        $rows  = $db->table('loan_installments')->where('loan_id', $loanId)->whereNotIn('payment_status', ['Paid', 'Partial'])
                    ->orderBy('installment_number', 'ASC')->get()->getResultArray();
        foreach ($rows as $r) {
            if ($left <= 0) {
                break;
            }
            $owed = round((float) $r['amount_due'] + (float) $r['carried_over'] - (float) $r['amount_paid'], 2);
            if ($owed <= 0) {
                continue;
            }
            $split[(int) $r['installment_id']] = min($owed, $left);
            $left = round($left - min($owed, $left), 2);
        }

        return $split;
    }

    // --------------------------------------------------------------- helpers

    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private static function key(string $s): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', trim($s)), '_'));
    }

    private function error(string $sheet, int $row, string $column, string $message): void
    {
        $this->errors[] = compact('sheet', 'row', 'column', 'message');
    }

    private function warning(string $sheet, int $row, string $column, string $message): void
    {
        $this->warnings[] = compact('sheet', 'row', 'column', 'message');
    }

    private function text(array $r, string $col): string
    {
        $v = $r[$col] ?? '';
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }

        return trim((string) $v);
    }

    /** A date cell, an Excel serial number, or text such as 2025-03-14 or 3/14/2025. */
    private function date(array $r, string $col, bool $required = true): ?string
    {
        $v     = $r[$col] ?? null;
        $sheet = $r['_sheet'];
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_int($v) || is_float($v)) {
            if ($v > 20000 && $v < 80000) {
                return date('Y-m-d', (int) round(($v - 25569) * 86400));
            }
        } elseif (is_string($v) && ($s = trim($v)) !== '') {
            foreach (['Y-m-d', 'Y/m/d', 'm/d/Y', 'n/j/Y', 'd-M-Y', 'M j, Y', 'F j, Y'] as $fmt) {
                $d = \DateTime::createFromFormat('!' . $fmt, $s);
                if ($d && $d->format($fmt) === $s) {
                    return $d->format('Y-m-d');
                }
            }
            $this->error($sheet, (int) $r['_row'], $col, "\"{$s}\" is not a date. Use a date cell or YYYY-MM-DD.");
            return null;
        }
        if ($v === null || $v === '') {
            if ($required) {
                $this->error($sheet, (int) $r['_row'], $col, 'Required.');
            }
            return null;
        }
        $this->error($sheet, (int) $r['_row'], $col, 'Not a date. Use a date cell or YYYY-MM-DD.');

        return null;
    }

    private function period(array $r, string $col): ?string
    {
        $v = $r[$col] ?? null;
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m');
        }
        $s = $this->text($r, $col);
        if (preg_match('/^(\d{4})[-\/](0?[1-9]|1[0-2])$/', $s, $m)) {
            return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        $this->error($r['_sheet'], (int) $r['_row'], $col, $s === '' ? 'Required.' : "\"{$s}\" is not a month. Use YYYY-MM, e.g. 2025-03.");

        return null;
    }

    private function money(array $r, string $col, bool $required = true): ?float
    {
        $v = $r[$col] ?? null;
        if (is_int($v) || is_float($v)) {
            return round((float) $v, 2);
        }
        $s = str_replace([',', '₱', 'PHP', 'php', '%', ' '], '', trim((string) $v));
        if ($s === '') {
            if ($required) {
                $this->error($r['_sheet'], (int) $r['_row'], $col, 'Required.');
            }
            return null;
        }
        if (! is_numeric($s)) {
            $this->error($r['_sheet'], (int) $r['_row'], $col, "\"{$v}\" is not a number.");
            return null;
        }

        return round((float) $s, 2);
    }

    private function int(array $r, string $col): ?int
    {
        $n = $this->money($r, $col);
        if ($n === null) {
            return null;
        }
        if (floor($n) !== $n) {
            $this->error($r['_sheet'], (int) $r['_row'], $col, 'Must be a whole number.');
            return null;
        }

        return (int) $n;
    }

    /** One of $allowed (case-insensitive), $default when blank. */
    private function choice(array $r, string $col, array $allowed, ?string $default, bool $required = false): ?string
    {
        $s = $this->text($r, $col);
        if ($s === '') {
            if ($required) {
                $this->error($r['_sheet'], (int) $r['_row'], $col, 'Required. Use ' . implode(' or ', $allowed) . '.');
            }
            return $default;
        }
        foreach ($allowed as $a) {
            if (strcasecmp($a, $s) === 0) {
                return $a;
            }
        }
        $this->error($r['_sheet'], (int) $r['_row'], $col, "\"{$s}\" is not allowed. Use " . implode(' or ', $allowed) . '.');

        return $default;
    }

    /** Flag values that already exist in the database. */
    private function taken($db, string $table, string $column, array $values, string $sheet, string $col, string $why): void
    {
        foreach (array_chunk(array_keys($values), 500) as $chunk) {
            $chunk = array_map('strval', $chunk);
            foreach ($db->table($table)->select($column)->whereIn($column, $chunk)->get()->getResultArray() as $hit) {
                $value = strtolower((string) $hit[$column]);
                $row   = $values[$value] ?? $values[strtoupper($value)] ?? $values[$hit[$column]] ?? 0;
                $this->error($sheet, (int) $row, $col, "\"{$hit[$column]}\" {$why}.");
            }
        }
    }

    /** Next free number after the highest "{prefix}NNN" in a column. */
    private function nextNumber($db, string $table, string $column, string $prefix): int
    {
        $max = 0;
        foreach ($db->table($table)->select($column)->like($column, $prefix, 'after')->get()->getResultArray() as $r) {
            $tail = substr($r[$column], strlen($prefix));
            if (ctype_digit($tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $max + 1;
    }
}
