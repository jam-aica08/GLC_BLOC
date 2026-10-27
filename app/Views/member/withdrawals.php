<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<?php
$hasAvailable = array_filter($accounts, fn($a) => $a['balance']['available'] > 0 && ! in_array($a['account_id'], $waitingFor));
$canRequest   = $available && ! $blockers && $hasAvailable;
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold mb-1"><i class="fa-solid fa-money-bill-transfer me-2 glc-icon"></i>My Withdrawals</h1>
        <p class="text-muted small mb-0">Withdraw contributions you have available. An admin reviews each request.</p>
    </div>
    <?php if ($canRequest): ?>
        <button type="button" class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#withdrawModal">
            <i class="fa-solid fa-plus me-1"></i>Request Withdrawal
        </button>
    <?php endif; ?>
</div>

<?= view('partials/flash') ?>

<?php if (! $available): ?>
    <div class="alert alert-warning small"><i class="fa-solid fa-circle-info me-1"></i>Withdrawals are not available yet. Please try again later or contact the cooperative office.</div>
<?php elseif ($blockers): ?>
    <div class="alert alert-warning small" role="status">
        <i class="fa-solid fa-lock me-1"></i><strong>Withdrawals are on hold:</strong> <?= esc($blockers[0]) ?>.
        <a href="<?= base_url('member/loans') ?>" class="alert-link">View my loans</a>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-wallet me-2"></i>Available to withdraw</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:0.875rem;">
            <thead style="background:#f8f9fa;">
                <tr><th class="px-4 py-3">Account #</th><th class="py-3">Status</th><th class="py-3 text-end">Contributions</th>
                    <th class="py-3 text-end">Withdrawn / offset</th><th class="py-3 text-end">Waiting</th><th class="py-3 text-end pe-4">Available</th></tr>
            </thead>
            <tbody>
                <?php if (! $accounts): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No accounts yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $a): $b = $a['balance']; ?>
                    <tr>
                        <td class="px-4 py-3"><code><?= esc($a['account_number']) ?></code> <span class="text-muted small">(<?= esc($a['account_type']) ?>)</span></td>
                        <td class="py-3"><span class="badge <?= $a['account_status'] === 'Active' ? 'bg-success' : ($a['account_status'] === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= esc($a['account_status'] === 'Inactive' ? 'Deactivated' : $a['account_status']) ?></span></td>
                        <td class="py-3 text-end">₱<?= number_format($b['contributed'], 2) ?></td>
                        <td class="py-3 text-end">₱<?= number_format($b['withdrawn'] + $b['offset'], 2) ?></td>
                        <td class="py-3 text-end"><?= $b['pending'] > 0 ? '₱' . number_format($b['pending'], 2) : '—' ?></td>
                        <td class="py-3 text-end pe-4 fw-semibold">₱<?= number_format($b['available'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">
        Withdrawing lowers your loan limit (twice the contributions you hold). Your contribution standing and dividend eligibility are not affected.
        Members with an outstanding loan cannot withdraw until every loan is fully paid.
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-clock-rotate-left me-2"></i>My requests</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:0.875rem;">
            <thead style="background:#f8f9fa;">
                <tr><th class="px-4 py-3">Requested</th><th class="py-3">Account #</th><th class="py-3 text-end">Amount</th><th class="py-3">Method</th><th class="py-3">Status</th></tr>
            </thead>
            <tbody>
                <?php if (! $requests): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No withdrawal requests yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td class="px-4 py-3 text-nowrap"><?= esc(date('M j, Y', strtotime($r['created_at']))) ?></td>
                        <td class="py-3"><code><?= esc($r['account_number']) ?></code></td>
                        <td class="py-3 text-end fw-semibold">₱<?= number_format((float) $r['amount'], 2) ?></td>
                        <td class="py-3"><?= esc($r['method'] ?? '—') ?></td>
                        <td class="py-3"><span class="badge <?= ['Approved' => 'bg-success', 'Rejected' => 'bg-danger'][$r['status']] ?? 'bg-warning text-dark' ?>"><?= esc($r['status'] === 'Pending' ? 'Waiting for approval' : $r['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canRequest): ?>
<div class="modal fade" id="withdrawModal" tabindex="-1" aria-labelledby="withdrawModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= base_url('member/withdrawals/request') ?>" onsubmit="this.querySelector('[type=submit]').disabled = true;">
            <?= csrf_field() ?>
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="withdrawModalLabel"><i class="fa-solid fa-money-bill-transfer me-2 glc-icon"></i>Request Withdrawal</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <label for="w_account" class="form-label fw-semibold small">Account <span class="text-danger">*</span></label>
                <select id="w_account" name="account_id" class="form-select mb-3" required>
                    <?php foreach ($hasAvailable as $a): ?>
                        <option value="<?= (int) $a['account_id'] ?>" data-max="<?= esc(number_format($a['balance']['available'], 2, '.', '')) ?>">
                            <?= esc($a['account_number']) ?> — ₱<?= number_format($a['balance']['available'], 2) ?> available
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="w_amount" class="form-label fw-semibold small">Amount (₱) <span class="text-danger">*</span></label>
                <input type="number" id="w_amount" name="amount" class="form-control" min="0.01" step="0.01" required aria-describedby="w_amount_help">
                <div id="w_amount_help" class="form-text mb-3">Up to the amount available on the account.</div>
                <label for="w_method" class="form-label fw-semibold small">Receive by <span class="text-danger">*</span></label>
                <select id="w_method" name="method" class="form-select mb-3" required>
                    <?php foreach ($methods as $m): ?><option value="<?= esc($m) ?>"><?= esc($m) ?></option><?php endforeach; ?>
                </select>
                <label for="w_reason" class="form-label fw-semibold small">Reason (optional)</label>
                <textarea id="w_reason" name="reason" class="form-control" rows="2" maxlength="255"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand"><i class="fa-solid fa-paper-plane me-2"></i>Send Request</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const account = document.getElementById('w_account'), amount = document.getElementById('w_amount'), help = document.getElementById('w_amount_help');
    function sync() {
        const max = account.selectedOptions[0] ? account.selectedOptions[0].dataset.max : '0';
        amount.max = max;
        help.textContent = 'Up to ₱' + Number(max).toLocaleString('en-PH', { minimumFractionDigits: 2 }) + ' on this account.';
    }
    account.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>

<?= $this->endSection() ?>
