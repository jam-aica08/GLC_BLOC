<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * GLC-BLOC data retention schedule. The retention review
 * (App\Services\RetentionService) and the privacy notice page both read it,
 * so the published policy and what the system does cannot drift apart.
 *
 * Periods marked "to confirm" are assumptions the cooperative, its auditor
 * and the adviser must confirm before the policy is formally adopted.
 */
class Retention extends BaseConfig
{
    // ── Inactive members (counted from the last sign-in, contribution or loan payment) ──

    /** Flag the member for review and notify them. */
    public int $inactiveFlagMonths = 6;

    /** Deactivate the membership and login (skipped while a loan is open or a hold is set). */
    public int $inactiveDeactivateMonths = 12;

    /** Offer the super admin to remove the deactivated member's sign-in data. */
    public int $inactiveLoginDataMonths = 24;

    /**
     * Financial records (contributions, loans, payments, dividends, financial
     * records, blockchain ledger) and the member's name tied to them: kept
     * this many years after the year of the member's last transaction. The
     * app never deletes them on its own. To confirm with the auditor (the
     * Tax Code and cooperative rules on keeping books of accounts).
     */
    public int $financialYears = 10;

    // ── Records the daily review deletes automatically ──

    /** Expired sign-in sessions. */
    public int $sessionDays = 2;

    /** Sign-in security events (logged in / out, failed and throttled sign-ins). */
    public int $securityLogDays = 180;

    /** Audit trail of administrator and member actions (kept with the financial records). */
    public int $auditLogYears = 10;

    /** Notifications already read. */
    public int $readNotificationDays = 180;

    /** Notifications never read. */
    public int $unreadNotificationDays = 365;

    /** Rejected account requests (open, deactivate or reactivate). */
    public int $rejectedRequestDays = 365;

    /** Audit actions counted as sign-in security events. */
    public array $securityActions = ['Logged in', 'Logged out', 'Failed login', 'Login throttled'];

    /**
     * The schedule as shown on the privacy notice: what, where, why, how long, what then.
     *
     * @return list<array{category: string, data: string, stored: string, purpose: string, period: string, action: string, mode: string}>
     */
    public function schedule(): array
    {
        return [
            [
                'category' => 'Member profile',
                'data'     => 'Member number, first and last name, membership date and status',
                'stored'   => 'members table',
                'purpose'  => 'Identify the owner of each account and its contributions, loans and dividends',
                'period'   => "While the membership is active; after it ends, kept with the financial records ({$this->financialYears} years after the last transaction)",
                'action'   => 'Name replaced with "Former member" (anonymized) by the super administrator',
                'mode'     => 'Manual, after review',
            ],
            [
                'category' => 'Sign-in data',
                'data'     => 'Username, email address, password (stored only as a one-way hash), last activity',
                'stored'   => 'users table',
                'purpose'  => 'Let the member or administrator sign in and receive notices',
                'period'   => "While the login is in use; reviewed after {$this->inactiveFlagMonths} months without activity, deactivated after {$this->inactiveDeactivateMonths} months, removable after {$this->inactiveLoginDataMonths} months",
                'action'   => 'Username and email replaced, password made unusable',
                'mode'     => 'Flag and deactivation automatic (daily); removal manual',
            ],
            [
                'category' => 'Cooperative accounts',
                'data'     => 'Account number, type (Basic / Pioneer), opening date, status, deactivation date and reason',
                'stored'   => 'cooperative_accounts table',
                'purpose'  => 'Track each share account, its standing and dividend eligibility',
                'period'   => "While open; a deactivated account is kept with its financial records ({$this->financialYears} years)",
                'action'   => 'Kept; never deleted while its records are kept',
                'mode'     => 'Deactivation manual (admin, or member request approved by an admin)',
            ],
            [
                'category' => 'Financial records',
                'data'     => 'Contributions, loans, installments, loan payments, dividends, approvals, blockchain ledger hashes',
                'stored'   => 'contributions, loans, loan_installments, loan_payments, dividends, financial_records, blockchain_ledger',
                'purpose'  => 'Books of account, audit, dividend computation, dispute resolution',
                'period'   => "{$this->financialYears} years after the end of the year of the last transaction (to confirm with the auditor)",
                'action'   => 'Reviewed by the super administrator and auditor; the app never deletes them on its own (the ledger hash chain must stay whole)',
                'mode'     => 'Manual',
            ],
            [
                'category' => 'Audit trail',
                'data'     => 'Who did what, on which record, when, result and IP address',
                'stored'   => 'audit_logs table',
                'purpose'  => 'Accountability for approvals and changes to member and financial data',
                'period'   => "{$this->auditLogYears} years",
                'action'   => 'Deleted',
                'mode'     => 'Automatic (daily)',
            ],
            [
                'category' => 'Sign-in security logs',
                'data'     => 'Sign-ins, sign-outs, failed and throttled attempts with IP address',
                'stored'   => 'audit_logs table',
                'purpose'  => 'Detect password guessing and investigate account misuse',
                'period'   => "{$this->securityLogDays} days",
                'action'   => 'Deleted',
                'mode'     => 'Automatic (daily)',
            ],
            [
                'category' => 'Notifications',
                'data'     => 'Updates about contributions, loans, dividends, requests and announcements',
                'stored'   => 'notifications, admin_notifications',
                'purpose'  => 'Tell members and administrators what needs attention',
                'period'   => "{$this->readNotificationDays} days after being read; {$this->unreadNotificationDays} days if never read",
                'action'   => 'Deleted',
                'mode'     => 'Automatic (daily)',
            ],
            [
                'category' => 'Account requests',
                'data'     => 'Requests to open, deactivate or reactivate an account, with the reason given',
                'stored'   => 'account_requests table',
                'purpose'  => 'Let an administrator review member requests',
                'period'   => "Approved: kept with the account. Rejected: {$this->rejectedRequestDays} days",
                'action'   => 'Rejected requests deleted',
                'mode'     => 'Automatic (daily)',
            ],
            [
                'category' => 'Sessions',
                'data'     => 'Session ID, IP address, sign-in state',
                'stored'   => 'ci_sessions table',
                'purpose'  => 'Keep a member signed in between pages',
                'period'   => "Ends after 20 minutes idle; deleted after {$this->sessionDays} days",
                'action'   => 'Deleted',
                'mode'     => 'Automatic (daily)',
            ],
            [
                'category' => 'Backups',
                'data'     => 'Copies of the whole database',
                'stored'   => 'TiDB Cloud automated backups',
                'purpose'  => 'Recover from data loss',
                'period'   => 'Rolling, per the TiDB Cloud plan\'s backup retention (to confirm)',
                'action'   => 'Expired by the provider; deleted data leaves the backups when they expire',
                'mode'     => 'Automatic (provider)',
            ],
        ];
    }
}
