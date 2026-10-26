<?php

namespace App\Libraries;

use Throwable;

/**
 * Append-only audit trail. Never stores passwords, tokens or form contents:
 * only who acted, what they did, on which record, when, and whether it worked.
 * Rows change only under the data retention policy (RetentionService): old
 * entries are deleted, and a removed member's username is replaced.
 */
class AuditLog
{
    /** Readable names for POST routes, matched on the path without its numeric ID. */
    private const ACTIONS = [
        'admin/members/add'                  => ['Added member', 'member'],
        'admin/members/deactivate'           => ['Deactivated member', 'member'],
        'admin/members/reactivate'           => ['Reactivated member', 'member'],
        'admin/members/accounts/add'         => ['Opened account for member', 'member'],
        'admin/members/accounts/deactivate'  => ['Deactivated account', 'cooperative_account'],
        'admin/members/accounts/reactivate'  => ['Reactivated account', 'cooperative_account'],
        'admin/retention/run'                => null,  // RetentionService records the run with its summary
        'admin/retention/hold'               => ['Changed legal hold', 'member'],
        'admin/retention/remove-login'       => ['Removed member sign-in data (retention)', 'member'],
        'admin/retention/anonymize'          => ['Anonymized member (retention)', 'member'],
        'member/profile/accounts/deactivate' => ['Requested account deactivation', 'cooperative_account'],
        'member/profile/accounts/reactivate' => ['Requested account reactivation', 'cooperative_account'],
        'admin/members/requests/approve'     => ['Approved account request', 'account_request'],
        'admin/members/requests/reject'      => ['Rejected account request', 'account_request'],
        'admin/members/announce'             => ['Posted announcement', null],
        'admin/contributions/approve'        => ['Approved contribution', 'contribution'],
        'admin/contributions/reject'         => ['Rejected contribution', 'contribution'],
        'admin/loans/approve'                => ['Approved loan', 'loan'],
        'admin/loans/reject'                 => ['Rejected loan', 'loan'],
        'admin/payments/approve'             => ['Approved loan payment', 'loan_payment'],
        'admin/payments/reject'              => ['Rejected loan payment', 'loan_payment'],
        'admin/financial-records/approve'    => ['Approved financial record', 'financial_record'],
        'admin/financial-records/reject'     => ['Rejected financial record', 'financial_record'],
        'admin/dividends/distribute'         => ['Created dividend distribution', 'dividend_distribution'],
        'admin/dividends/approve'            => ['Approved dividend distribution', 'dividend_distribution'],
        'admin/dividends/reject'             => ['Rejected dividend distribution', 'dividend_distribution'],
        'admin/ledger/verify'                => ['Verified blockchain ledger', 'blockchain_ledger'],
        'admin/settings/password'            => ['Changed own password', 'user'],
        'admin/settings/admins'              => ['Added administrator', 'user'],
        'admin/settings/updates'             => ['Applied database updates', null],
        'admin/settings/reset'               => ['Reset data', null],
        'member/contributions/submit'        => ['Submitted contribution', 'contribution'],
        'member/loans/apply'                 => ['Applied for loan', 'loan'],
        'member/loans/pay'                   => ['Submitted loan payment', 'loan'],
        'member/profile/accounts/add'        => ['Requested new account', 'account_request'],
        'member/settings/password'           => ['Changed own password', 'user'],
        'member/notifications/read'          => null,  // not worth auditing
        'member/notifications/read-all'      => null,
        'admin/notifications/read'           => null,
        'admin/notifications/read-all'       => null,
        'admin/notifications/seen'           => null,  // opening the bell
        'member/notifications/seen'          => null,
    ];

    public static function available(): bool
    {
        try {
            return SchemaCache::passes('table_audit_logs', static fn () => db_connect()->tableExists('audit_logs'));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array{user_id?: ?int, username?: ?string, role?: ?string} $actor Defaults to the signed-in user.
     */
    public static function record(string $action, ?string $entityType = null, $entityId = null, ?string $details = null, bool $ok = true, ?array $actor = null): void
    {
        try {
            if (! self::available()) {
                return;
            }
            $session = session();
            $actor ??= [
                'user_id'  => $session->get('user_id'),
                'username' => $session->get('username'),
                'role'     => self::roleLabel($session->get('role'), (bool) $session->get('is_super_admin')),
            ];

            db_connect()->table('audit_logs')->insert([
                'user_id'     => $actor['user_id'] ?? null,
                'username'    => isset($actor['username']) ? mb_substr((string) $actor['username'], 0, 100) : null,
                'role'        => $actor['role'] ?? null,
                'action'      => mb_substr($action, 0, 100),
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : (string) $entityId,
                'details'     => $details === null ? null : mb_strimwidth(strip_tags($details), 0, 500, '…'),
                'result'      => $ok ? 'Success' : 'Failed',
                'ip_address'  => service('request')->getIPAddress(),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the action itself.
            log_message('error', 'AuditLog::record failed: ' . $e->getMessage());
        }
    }

    public static function roleLabel(?string $role, bool $isSuper): ?string
    {
        if ($role === null) {
            return null;
        }

        return $isSuper ? 'Super Admin' : ucfirst(strtolower($role));
    }

    /**
     * Record a POST request after it ran, using the flash message it set as
     * the details and its type (success / error) as the result.
     */
    public static function recordRequest(string $path, ?string $jsonBody = null): void
    {
        $path   = trim($path, '/');
        $id     = null;
        $parts  = [];
        foreach (explode('/', $path) as $segment) {
            if (ctype_digit($segment)) {
                $id ??= $segment;
                continue;
            }
            $parts[] = $segment;
        }
        $key = implode('/', $parts);

        if (array_key_exists($key, self::ACTIONS) && self::ACTIONS[$key] === null) {
            return;
        }
        [$action, $entity] = self::ACTIONS[$key] ?? ['POST ' . $key, null];

        // Background (AJAX) requests answer with JSON {ok, message} instead of a flash message.
        $json = $jsonBody !== null ? json_decode($jsonBody, true) : null;
        if (is_array($json) && array_key_exists('ok', $json)) {
            self::record($action, $entity, $id, isset($json['message']) ? (string) $json['message'] : null, (bool) $json['ok']);
            return;
        }

        $session = session();
        $error   = $session->getFlashdata('error');
        $success = $session->getFlashdata('success');
        $details = is_string($error) ? $error : (is_string($success) ? $success : null);

        self::record($action, $entity, $id, $details, ! is_string($error));
    }
}
