<?php

namespace App\Libraries;

use App\Models\CooperativeAccountModel;
use App\Services\AccountRequestService;
use App\Services\AccountStatusService;
use App\Services\ContributionBalance;
use App\Services\LedgerService;
use App\Services\LoanOffsetService;
use App\Services\LoanService;
use App\Services\Notifier;
use App\Services\WithdrawalService;

/**
 * After the demo import, puts a few members into the situations the newer
 * policies cover, through the same services the app uses, so every screen
 * has something to show:
 *  - withdrawals: two approved, one waiting for an admin
 *  - two deactivated accounts and one reactivating (missed months to settle)
 *  - a loan with missed months settled by a contribution offset, and one
 *    overdue loan left for an admin to offset during the demo
 *  - a member's request to deactivate an account, waiting for an admin
 * Members who stopped contributing months ago are used for deactivation and
 * reactivation, so the missed months are real.
 */
class DemoShowcase
{
    private $db;
    private array $taken = [];
    private array $log   = [];

    public function __construct(private int $adminId)
    {
        $this->db = db_connect();
    }

    /** @return list<string> one line per situation created */
    public function apply(): array
    {
        if (! WithdrawalService::available() || ! AccountStatusService::available()) {
            return ['Showcase skipped: apply the database updates first.'];
        }
        model(CooperativeAccountModel::class)->syncStatuses();

        // Stopped contributing 3+ months ago: one reactivating, two deactivated.
        $lapsed = $this->candidates(lapsedMonths: 3);
        if ($a = array_shift($lapsed)) {
            $this->reactivating($a);
        }
        foreach (array_slice($lapsed, 0, 2) as $i => $a) {
            $this->deactivated($a, $i === 0 ? 'Unable to continue monthly contributions for now' : 'Working abroad this year');
        }

        // Paying members with no loan: withdrawals, offsets and a request.
        $paying = $this->candidates(minAvailable: 3000);
        if ($a = array_shift($paying)) {
            $this->withdrawal($a, 1500, 'GCash', 'School fees', true);
        }
        if ($a = array_shift($paying)) {
            $this->withdrawal($a, 2000, 'Cash', 'Medical expenses', true);
        }
        if ($a = array_shift($paying)) {
            $this->withdrawal($a, 1000, 'Bank transfer', 'Home repairs', false);
        }
        if ($a = array_shift($paying)) {
            $this->overdueLoan($a, '-100 days', true);
        }
        if ($a = array_shift($paying)) {
            $this->overdueLoan($a, '-70 days', false);
        }
        if ($a = array_shift($paying)) {
            $this->deactivationRequest($a);
        }

        return $this->log;
    }

    /**
     * Active accounts of Active members who have no outstanding loan, nothing
     * waiting for approval, and either PHP $minAvailable to spare or no
     * contribution for the last $lapsedMonths months. One account per member.
     */
    private function candidates(int $minAvailable = 0, int $lapsedMonths = 0): array
    {
        $rows = $this->db->table('cooperative_accounts ca')
            ->select("ca.account_id, ca.account_number, ca.account_type, ca.member_id, CONCAT(m.first_name, ' ', m.last_name) AS name,
                      (SELECT MAX(c.contribution_period) FROM contributions c WHERE c.account_id = ca.account_id AND c.payment_status = 'Approved') AS last_period", false)
            ->join('members m', 'm.member_id = ca.member_id')
            ->where('ca.account_status', 'Active')->where('m.membership_status', 'Active')
            ->orderBy('ca.account_id')->get()->getResultArray();

        $cutoff   = date('Y-m', strtotime("first day of -{$lapsedMonths} months"));
        $blockers = (new AccountStatusService())->blockersFor(array_column($rows, 'account_id'));
        $balances = ContributionBalance::forAccounts(array_column($rows, 'account_id'));
        $out      = [];
        foreach ($rows as $r) {
            $member = (int) $r['member_id'];
            if (isset($this->taken[$member]) || $blockers[(int) $r['account_id']] || ContributionBalance::outstandingLoans($member)) {
                continue;
            }
            if ($lapsedMonths > 0 ? ($r['last_period'] === null || $r['last_period'] >= $cutoff)
                                  : ($balances[(int) $r['account_id']]['available'] < $minAvailable || $r['last_period'] < date('Y-m', strtotime('first day of -1 month')))) {
                continue;
            }
            $out[] = $r;
            $this->taken[$member] = true;
        }

        return $out;
    }

    private function reactivating(array $a): void
    {
        $service = new AccountStatusService();
        $service->deactivate((int) $a['account_id'], $this->adminId, 'Could not contribute for a while');
        // Stopped the month after the last contribution.
        $stopped = date('Y-m-05 09:00:00', strtotime($a['last_period'] . '-01 +1 month'));
        $this->db->table('cooperative_accounts')->where('account_id', $a['account_id'])->update(['deactivated_at' => $stopped]);
        $service->reactivate((int) $a['account_id']);
        $owed = $service->arrears((int) $a['account_id'])['owed'];
        $this->log[] = "Reactivating: {$a['name']} ({$a['account_number']}) owes PHP " . number_format($owed, 2) . ' in missed months';
    }

    private function deactivated(array $a, string $reason): void
    {
        (new AccountStatusService())->deactivate((int) $a['account_id'], $this->adminId, $reason);
        $this->db->table('cooperative_accounts')->where('account_id', $a['account_id'])
            ->update(['deactivated_at' => date('Y-m-05 09:00:00', strtotime($a['last_period'] . '-01 +1 month'))]);
        $this->log[] = "Deactivated: {$a['name']} ({$a['account_number']})";
    }

    private function withdrawal(array $a, float $amount, string $method, string $reason, bool $approve): void
    {
        $userId = (int) $this->db->table('members')->select('user_id')->where('member_id', $a['member_id'])->get()->getRow()->user_id;
        $id     = (new WithdrawalService())->request((int) $a['member_id'], (int) $a['account_id'], $amount, $method, $reason, $userId);
        if (is_string($id)) {
            $this->log[] = "Withdrawal for {$a['name']} skipped: {$id}";
            return;
        }
        $w = $this->db->table('withdrawals')->where('withdrawal_id', $id)->get()->getRowArray();
        if (! $approve) {
            Notifier::toAdmins('withdrawal', 'Withdrawal to review', "{$a['name']} asked to withdraw PHP " . number_format($amount, 2)
                . " from account {$a['account_number']} ({$method}).", 'admin/withdrawals', 'review-record-' . $w['financial_record_id']);
            $this->log[] = "Withdrawal waiting for approval: {$a['name']} PHP " . number_format($amount, 2);
            return;
        }

        // Approved the way an administrator approves it: record, withdrawal, ledger, notice.
        $now = date('Y-m-d H:i:s');
        $this->db->table('financial_records')->where('financial_record_id', $w['financial_record_id'])->where('approval_status', 'Pending')
            ->update(['approval_status' => 'Approved', 'first_approved_by' => $this->adminId, 'first_approval_date' => $now, 'updated_at' => $now]);
        $this->db->table('withdrawals')->where('withdrawal_id', $id)->update(['status' => 'Approved', 'updated_at' => $now]);
        $record = $this->db->table('financial_records')->where('financial_record_id', $w['financial_record_id'])->get()->getRowArray();
        (new LedgerService())->createEntry((int) $w['financial_record_id'], $record);
        Notifier::toMember((int) $a['member_id'], 'withdrawal', 'Withdrawal approved',
            'Your withdrawal of PHP ' . number_format($amount, 2) . " from account {$a['account_number']} was approved ({$method}).",
            'member/withdrawals', 'withdrawal-approved-' . $id);
        $this->log[] = "Withdrawal approved: {$a['name']} PHP " . number_format($amount, 2);
    }

    /** A loan released $released, never paid; with $offset its overdue months are settled from contributions. */
    private function overdueLoan(array $a, string $released, bool $offset): void
    {
        $limit  = (new LoanService())->checkMaxLoanLimit((int) $a['account_id']);
        $amount = (float) min(6000, floor($limit / 2 / 100) * 100);
        if ($amount < 1000) {
            return;
        }
        $rate    = CooperativeAccountModel::LOAN_INTEREST_RATE[$a['account_type']] ?? 5.0;
        $release = date('Y-m-d', strtotime($released));
        $now     = date('Y-m-d H:i:s');

        $this->db->table('loans')->insert([
            'account_id' => $a['account_id'], 'total_contributions' => round($limit / 2, 2), 'maximum_loan_limit' => $limit,
            'loan_amount' => $amount, 'interest_rate' => $rate, 'interest_amount' => round($amount * $rate / 100, 2), 'loan_term_months' => 6,
            'application_date' => date('Y-m-d', strtotime($released . ' -3 days')), 'approval_date' => $release, 'release_date' => $release,
            'loan_status' => 'Active', 'approved_by' => $this->adminId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $loanId = (int) $this->db->insertID();
        foreach ((new LoanService())->calculateInstallments(['loan_amount' => $amount, 'interest_rate' => $rate, 'loan_term_months' => 6, 'release_date' => $release]) as $row) {
            $this->db->table('loan_installments')->insert($row + ['loan_id' => $loanId, 'amount_paid' => 0, 'payment_status' => 'Upcoming', 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->db->table('financial_records')->insert([
            'account_id' => $a['account_id'], 'record_type' => 'Loan Disbursement', 'transaction_date' => $release, 'amount' => $amount,
            'approval_status' => 'Approved', 'processed_by' => $this->adminId, 'first_approved_by' => $this->adminId,
            'first_approval_date' => $release . ' 09:00:00', 'reference_id' => $loanId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $frId = (int) $this->db->insertID();
        (new LedgerService())->createEntry($frId, $this->db->table('financial_records')->where('financial_record_id', $frId)->get()->getRowArray());

        if (! $offset) {
            $this->log[] = "Overdue loan #{$loanId} (no offset yet, for the demo): {$a['name']} PHP " . number_format($amount, 2);
            return;
        }
        $preview = (new LoanOffsetService())->preview($loanId);
        $error   = (new LoanOffsetService())->apply($loanId, min($preview['overdue'], $preview['max']), $this->adminId);
        $this->log[] = $error === null
            ? "Loan #{$loanId} offset: {$a['name']} PHP " . number_format(min($preview['overdue'], $preview['max']), 2) . ' of missed months settled from contributions'
            : "Loan #{$loanId} offset skipped: {$error}";
    }

    private function deactivationRequest(array $a): void
    {
        $id = (new AccountRequestService())->createChange((int) $a['member_id'], (int) $a['account_id'], 'Deactivate', 'Moving abroad for work');
        if (is_string($id)) {
            $this->log[] = "Deactivation request skipped: {$id}";
            return;
        }
        Notifier::toAdmins('account', 'Account request to review', "{$a['name']} asked to deactivate account {$a['account_number']}.",
            'admin/members', 'review-request-' . $id);
        $this->log[] = "Deactivation request waiting: {$a['name']} ({$a['account_number']})";
    }
}
