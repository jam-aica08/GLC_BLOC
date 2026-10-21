<?php

namespace App\Services;

use App\Models\CooperativeAccountModel;

/**
 * Requests from members to open another account. A request waits for an
 * admin; when approved the account is opened (Pending until January up to
 * the approval month is paid), when rejected nothing is opened.
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
        return $this->db()->tableExists('account_requests');
    }

    public function hasPending(int $memberId): bool
    {
        return $this->available() && $this->db()->table('account_requests')
            ->where('member_id', $memberId)->where('status', 'Pending')->countAllResults() > 0;
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

        return $this->db()->table('account_requests')
            ->where('member_id', $memberId)
            ->groupStart()
                ->where('status', 'Pending')
                ->orGroupStart()->where('status', 'Rejected')->where('reviewed_at >=', date('Y-m-d H:i:s', strtotime('-30 days')))->groupEnd()
            ->groupEnd()
            ->orderBy('request_id', 'DESC')
            ->get()->getResultArray();
    }

    /** Every waiting request with the member's name, oldest first. */
    public function pending(): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->db()->table('account_requests r')
            ->select("r.*, m.member_number, CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.membership_status")
            ->join('members m', 'm.member_id = r.member_id')
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
            $row = $this->db()->table('account_requests')->where('request_id', $requestId)->get()->getRowArray();
            Notifier::toMember((int) $row['member_id'], 'account', 'Account request rejected',
                "Your request for a {$row['account_type']} account was rejected.", 'member/profile', 'request-rejected-' . $requestId);
        }

        return $rejected;
    }
}
