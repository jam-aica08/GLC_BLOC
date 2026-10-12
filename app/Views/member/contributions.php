<?= $this->extend('layouts/member_main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">My Contributions</h4>
        <p class="text-muted mb-0 small">View your contribution history and submit new contributions.</p>
    </div>
    <button type="button" class="btn btn-sm text-white" style="background:#1a1a2e;"
            data-bs-toggle="modal" data-bs-target="#newContributionModal">
        <i class="fa-solid fa-plus me-2"></i>Submit Contribution
    </button>
</div>

<!-- Flash Messages -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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

<!-- Contributions History Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-piggy-bank me-2" style="color:#f0a500;"></i>Contribution History</span>
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
                        <th class="py-3">Payment Method</th>
                        <th class="py-3">Date Submitted</th>
                        <th class="py-3">Payment Status</th>
                        <th class="py-3">Approval Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($contributions)): ?>
                        <?php foreach ($contributions as $contribution): ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($contribution['id']) ?></code></td>
                            <td class="py-3"><?= esc($contribution['contribution_period'] ?? $contribution['period'] ?? '-') ?></td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($contribution['amount'] ?? 0, 2) ?></td>
                            <td class="py-3"><?= esc($contribution['payment_method'] ?? '-') ?></td>
                            <td class="py-3 text-muted small"><?= esc($contribution['created_at'] ?? '-') ?></td>
                            <td class="py-3">
                                <?php $ps = $contribution['payment_status'] ?? 'Unpaid'; ?>
                                <span class="badge <?= ($ps === 'Paid') ? 'bg-success' : (($ps === 'Partial') ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                    <?= esc($ps) ?>
                                </span>
                            </td>
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
                            <td colspan="7" class="text-center py-5 text-muted">
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
            <div class="modal-header" style="background:#1a1a2e;">
                <h5 class="modal-title text-white" id="newContributionModalLabel">
                    <i class="fa-solid fa-piggy-bank me-2" style="color:#f0a500;"></i>Submit New Contribution
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= base_url('member/contributions/submit') ?>" method="post">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <?php if (count($accounts ?? []) > 1): ?>
                    <div class="mb-3">
                        <label for="account_id" class="form-label fw-semibold small">Account <span class="text-danger">*</span></label>
                        <select class="form-select" id="account_id" name="account_id" required>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= esc($acc['account_id']) ?>"><?= esc($acc['account_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="account_id" value="<?= esc($accounts[0]['account_id'] ?? '') ?>">
                    <?php endif; ?>
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
                                   placeholder="0.00" min="1" step="0.01" required
                                   value="<?= esc(old('amount')) ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:#1a1a2e;">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
