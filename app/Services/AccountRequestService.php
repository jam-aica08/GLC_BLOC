<?php

namespace App\Services;

use App\Models\CooperativeAccountModel;

/**
 * Requests from members about their accounts, each waiting for an admin:
 *  - Open: when approved the account is opened (Pending until January up to
 *    the approval month is paid); when rejected nothing is opened.
 *  - Deactivate / Reactivate one existing account: when approved the change
 *    is made through AccountStatusService, with the same checks as when an
 *    admin does it directly.
 */
class AccountRequestService
{
    private function db()
    {
        return db_connect();
    }

    /** False until "Apply Database Updates" has created the table. */
    public function available(): bool
    {
        return \App\Libraries\SchemaCache::passes('table_account_requests', fn () => $this->db()->tableExists('account_requests'));
    }

    /** Whether deactivate / reactivate requests can be stored (needs the latest database update). */
    public function changesAvailable(): bool
    {
        return $this->available() && AccountStatusService::available()
            && \App\Libraries\SchemaCache::passes('account_requests_request_type', fn () => $this->db()->fieldExists('request_type', 'account_requests'));
    }

    /** Whether the member already has a request to open an account waiting. */
    public function hasPending(int $memberId): bool
    {
        if (! $this->available()) {
            return false;
        }
        $query = $this->db()->table('account_requests')->where('member_id', $memberId)->where('status', 'Pending');
        if ($this->changesAvailable()) {
            $query->where('request_type', 'Open');
        }

        return $query->countAllResults() > 0;
    }

    /**
     * Waiting deactivate / reactivate requests of a member, by account.
     *
     * @return array<int, string> account_id => 'Deactivate' | 'Reactivate'
     */
    public function pendingChanges(int $memberId): array
    {
        if (! $this->changesAvailable()) {
            return [];
        }
        $rows = $this->db()->table('account_requests')->select('account_id, request_type')
            ->where('member_id', $memberId)->where('status', 'Pending')->whereIn('request_type', ['Deactivate', 'Reactivate'])
            ->get()->getResultArray();

        return array_column($rows, 'request_type', 'account_id');
    }

    /**
     * A member asks to deactivate or reactivate one of their own accounts.
     * Returns the new request's ID, or an error message.
     */
    public function createChange(int $memberId, int $accountId, string $type, string $reason = ''): int|string
    {
        if (! $this->changesAvailable()) {
            return 'Account requests are not available yet. Please try again later.';
        }
        if (! in_array($type, ['Deactivate', 'Reactivate'], true)) {
            return 'Unknown request.';
        }
        $account = $this->db()->table('cooperative_accounts')->where('account_id', $accountId)->where('member_id', $memberId)->get()->getRowArray();
        if (! $account) {
            return 'Account not found.';
        }
        if (isset($this->pendingChanges($memberId)[$accountId])) {
            return "You already have a request for account {$account['account_number']} waiting for approval.";
        }
        if ($type === 'Deactivate') {
            if (in_array($account['account_status'], ['Inactive', 'Closed'], true)) {
                return "Account {$account['account_number']} is already {$account['account_status']}.";
            }
            if ($blockers = (new AccountStatusService())->blockers($accountId)) {
                return "Account {$account['account_number']} cannot be deactivated yet: " . implode('; ', $blockers) . '.';
            }
        } elseif ($account['account_status'] !== 'Inactive') {
            return "Account {$account['account_number']} is not deactivated.";
        }

        $now    = date('Y-m-d H:i:s');
        $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
        $this->db()->table('account_requests')->insert([
            'member_id'    => $memberId,
            'request_type' => $type,
            'account_type' => $account['account_type'],
            'reason'       => $reason === '' ? null : $reason,
            'account_id'   => $accountId,
            'status'       => 'Pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return (int) $this->db()->insertID();
    }

    /** Returns the new request's ID. */
    public function create(int $memberId, string $accountType): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db()->table('account_requests')->insert([
            'member_id'    => $memberId,
            'account_type' => $accountType,
            'status'       => 'Pending',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return (int) $this->db()->insertID();
    }

    /** Waiting and recently rejected requests of one member, newest first. */
    public function forMember(int $memberId): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->db()->table('account_requests r')
            ->select('r.*, ca.account_number')
            ->join('cooperative_accounts ca', 'ca.account_id = r.account_id', 'left')
            ->where('r.member_id', $memberId)
            ->groupStart()
                ->where('r.status', 'Pending')
                ->orGroupStart()->where('r.status', 'Rejected')->where('r.reviewed_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))->groupEnd()
            ->groupEnd()
            ->orderBy('r.request_id', 'DESC')
            ->get()->getResultArray();
    }

    /** Every waiting request with the member's name, oldest first. */
    public function pending(): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->db()->table('account_requests r')
            ->select("r.*, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.membership_status, ca.account_number, ca.account_status")
            ->join('members m', 'm.member_id = r.member_id')
            ->join('cooperative_accounts ca', 'ca.account_id = r.account_id', 'left')
            ->where('r.status', 'Pending')
            ->orderBy('r.request_id', 'ASC')
            ->get()->getResultArray();
    }

    public function pendingCount(): int
    {
        return $this->available()
            ? $this->db()->table('account_requests')->where('status', 'Pending')->countAllResults()
            : 0;
    }

    /**
     * Approve a waiting request and open the account. Returns the new
     * account number, or an error message.
     *
     * @return array{0: ?string, 1: ?string} [accountNumber, error]
     */
    public function approve(int $requestId, int $adminId): array
    {
        $db      = $this->db();
        $request = $db->table('account_requests')->where('request_id', $requestId)->get()->getRowArray();
        if (! $request || $request['status'] !== 'Pending') {
            return [null, 'This request is no longer waiting for approval.'];
        }
        if (in_array($request['request_type'] ?? 'Open', ['Deactivate', 'Reactivate'], true)) {
            return $this->approveChange($request, $adminId);
        }

        $member = $db->table('members')->where('member_id', $request['member_id'])->get()->getRowArray();
        if (($member['membership_status'] ?? '') !== 'Active') {
            return [null, 'Reactivate this member before approving the request.'];
        }

        $db->transStart();
        $accountModel  = model(CooperativeAccountModel::class);
        $accountNumber = $accountModel->openAccount((int) $request['member_id'], $request['account_type']);
        $accountId     = (int) $db->table('cooperative_accounts')->select('account_id')
                                  ->where('account_number', $accountNumber)->get()->getRowArray()['account_id'];
        $db->table('account_requests')->where('request_id', $requestId)->where('status', 'Pending')->update([
            'status'      => 'Approved',
            'account_id'  => $accountId,
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $db->transComplete();

        if (! $db->transStatus()) {
            return [null, 'Could not open the account. Please try again.'];
        }
        Notifier::toMember((int) $request['member_id'], 'account', 'Account request approved',
            "Your {$request['account_type']} account {$accountNumber} is open. Pay PHP "
            . number_format(CooperativeAccountModel::joinAmount(), 2) . ' (January to ' . date('F') . ') to activate it.',
            'member/contributions', 'request-approved-' . $requestId);

        return [$accountNumber, null];
    }

    /**
     * Deactivate or reactivate the requested account. The checks are the same
     * as when an admin does it directly, so a request made before a new loan
     * cannot deactivate an account that now owes money.
     *
     * @return array{0: ?string, 1: ?string} [accountNumber, error]
     */
    private function approveChange(array $request, int $adminId): array
    {
        $accountId = (int) $request['account_id'];
        $service   = new AccountStatusService();
        $account   = $service->find($accountId);
        if (! $account) {
            return [null, 'The account in this request no longer exists.'];
        }

        $error = $request['request_type'] === 'Deactivate'
            ? $service->deactivate($accountId, $adminId, 'requested by the member' . ($request['reason'] ? ': ' . $request['reason'] : ''))
            : $service->reactivate($accountId);
        if ($error !== null) {
            return [null, $error];
        }

        $this->db()->table('account_requests')->where('request_id', $request['request_id'])->where('status', 'Pending')->update([
            'status'      => 'Approved',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return [$account['account_number'], null];
    }

    public function reject(int $requestId, int $adminId): bool
    {
        $this->db()->table('account_requests')->where('request_id', $requestId)->where('status', 'Pending')->update([
            'status'      => 'Rejected',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $rejected = $this->db()->affectedRows() > 0;
        if ($rejected) {
            $row  = $this->db()->table('account_requests r')->select('r.*, ca.account_number')
                ->join('cooperative_accounts ca', 'ca.account_id = r.account_id', 'left')
                ->where('r.request_id', $requestId)->get()->getRowArray();
            $what = match ($row['request_type'] ?? 'Open') {
                'Deactivate' => "to deactivate account {$row['account_number']}",
                'Reactivate' => "to reactivate account {$row['account_number']}",
                default      => "for a {$row['account_type']} account",
            };
            Notifier::toMember((int) $row['member_id'], 'account', 'Account request rejected',
                "Your request {$what} was rejected.", 'member/profile', 'request-rejected-' . $requestId);
        }

        return $rejected;
    }
}
