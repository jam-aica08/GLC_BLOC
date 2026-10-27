<?= $this->extend('layouts/member_main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1 fw-bold">My Contributions</h1>
        <p class="text-muted mb-0 small">View your contribution history and submit new contributions.</p>
    </div>
    <button type="button" class="btn btn-sm btn-brand"
            data-bs-toggle="modal" data-bs-target="#newContributionModal">
        <i class="fa-solid fa-plus me-2"></i>Submit Contribution
    </button>
</div>

<!-- Flash Messages -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Total Paid</div>
                <div class="fw-bold fs-5 text-success">&#8369;<?= number_format($totalPaid ?? 0, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Pending Approval</div>
                <div class="fw-bold fs-5 text-warning"><?= $pendingCount ?? 0 ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Total Records</div>
                <div class="fw-bold fs-5"><?= count($contributions ?? []) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Account Standing -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="fa-solid fa-scale-balanced me-2 glc-icon"></i>Account Standing (₱<?= number_format(\App\Models\CooperativeAccountModel::MONTHLY_CONTRIBUTION, 2) ?> per month)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Required to Date</th>
                        <th class="py-3">Paid (Approved)</th>
                        <th class="py-3">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($accounts ?? []) as $acc): $st = $acc['standing']; ?>
                    <tr>
                        <td class="px-4 py-3"><code><?= esc($acc['account_number']) ?></code> <span class="text-muted small">(<?= esc($acc['account_type']) ?>)</span></td>
                        <td class="py-3">
                            <?php if (! empty($st['reactivating'])): ?>
                                <span class="badge bg-warning text-dark">Reactivating — ₱<?= number_format($st['join_balance'], 2) ?> missed contributions to settle</span>
                                <div class="small text-muted">From <?= esc(date('F Y', strtotime($st['reactivation_from'] . '-01'))) ?> to <?= esc(date('F Y')) ?></div>
                            <?php elseif ($acc['account_status'] === 'Pending'): ?>
                                <span class="badge bg-warning text-dark">Joining — ₱<?= number_format($st['join_balance'], 2) ?> left</span>
                            <?php else: ?>
                                <span class="badge <?= $acc['account_status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= esc($acc['account_status'] === 'Inactive' ? 'Deactivated' : $acc['account_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if (in_array($acc['account_status'], ['Inactive', 'Closed'], true)): /* no contributions are due on a deactivated account */ ?>
                            <td class="py-3">—</td>
                            <td class="py-3">₱<?= number_format($st['paid'], 2) ?></td>
                            <td class="py-3">—</td>
                        <?php else: ?>
                        <td class="py-3">₱<?= number_format($st['required_to_date'], 2) ?></td>
                        <td class="py-3">₱<?= number_format($st['paid'], 2) ?></td>
                        <td class="py-3 fw-semibold <?= $st['due_balance'] > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($st['due_balance'], 2) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Contributions History Table -->
<div class="card" data-search>
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-piggy-bank me-2 glc-icon"></i>Contribution History</span>
        <span class="badge bg-secondary"><?= count($contributions ?? []) ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">ID</th>
                        <th class="py-3">Period</th>
                        <th class="py-3">Amount</th>
                        <th class="py-3">Date Submitted</th>
                        <th class="py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($contributions)): ?>
                        <?php foreach ($contributions as $contribution): ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($contribution['id']) ?></code></td>
                            <td class="py-3"><?= esc($contribution['contribution_period'] ?? '-') ?></td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($contribution['amount'] ?? 0, 2) ?></td>
                            <td class="py-3 text-muted small"><?= esc($contribution['created_at'] ?? '-') ?></td>
                            <td class="py-3">
                                <?php $as = $contribution['approval_status'] ?? 'Pending'; ?>
                                <span class="badge <?= ($as === 'Approved') ? 'bg-success' : (($as === 'Rejected') ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                    <?= esc($as) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-piggy-bank fa-2x mb-3 d-block opacity-25"></i>
                                No contributions yet. Click "Submit Contribution" to get started.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New Contribution Modal -->
<div class="modal fade" id="newContributionModal" tabindex="-1" aria-labelledby="newContributionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="newContributionModalLabel">
                    <i class="fa-solid fa-piggy-bank me-2 glc-icon"></i>Submit New Contribution
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= base_url('member/contributions/submit') ?>" method="post">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <?php $monthly = \App\Models\CooperativeAccountModel::MONTHLY_CONTRIBUTION; ?>
                    <div class="mb-3">
                        <label for="account_id" class="form-label fw-semibold small">Account <span class="text-danger">*</span></label>
                        <select class="form-select" id="account_id" name="account_id" required>
                            <?php foreach (($accounts ?? []) as $acc): if (! in_array($acc['account_status'], ['Active', 'Pending', 'Inactive'], true)) continue; ?>
                                <option value="<?= esc($acc['account_id']) ?>" data-min="<?= esc(number_format($acc['min_contribution'], 2, '.', '')) ?>" data-left="<?= esc(number_format($acc['left_to_activate'], 2, '.', '')) ?>" data-reactivating="<?= ! empty($acc['standing']['reactivating']) ? '1' : '' ?>">
                                    <?= esc($acc['account_number']) ?> (<?= esc($acc['account_type']) ?><?= $acc['account_status'] === 'Pending' ? (! empty($acc['standing']['reactivating']) ? ' — reactivating, ₱' : ' — joining, ₱') . number_format($acc['standing']['join_balance'], 2) . ' left' : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="contribution_period" class="form-label fw-semibold small">Contribution Period <span class="text-danger">*</span></label>
                        <input type="month" class="form-control" id="contribution_period"
                               name="contribution_period" required
                               value="<?= esc(old('contribution_period', date('Y-m'))) ?>">
                        <div class="form-text">Select the month and year this contribution is for.</div>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label fw-semibold small">Amount (&#8369;) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">&#8369;</span>
                            <input type="number" class="form-control" id="amount" name="amount"
                                   step="0.01" min="300" max="<?= \App\Models\CooperativeAccountModel::CONTRIBUTION_MAX ?>" required value="300">
                        </div>
                        <div class="form-text" id="amountHelp" aria-live="polite"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Shows what is left to activate a Pending account; the minimum is PHP 300 (or less when less is left).
(function () {
    const select = document.getElementById('account_id');
    const amount = document.getElementById('amount');
    const help   = document.getElementById('amountHelp');
    if (!select || !amount) return;
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
    function apply() {
        const option = select.options[select.selectedIndex];
        if (!option) return;
        const min  = parseFloat(option.dataset.min || '300');
        const left = parseFloat(option.dataset.left || '0');
        amount.min = min;
        if (!amount.value || parseFloat(amount.value) < min) amount.value = min.toFixed(2);
        help.textContent = left > 0
            ? peso(left) + (option.dataset.reactivating ? ' of missed monthly contributions left before this account is active again.' : ' left to activate this account.') + ' You can pay it in parts.'
            : '₱300 a month. You can pay up to ₱3,600 at once.';
    }
    select.addEventListener('change', apply);
    apply();
})();
</script>

<?= $this->endSection() ?>
