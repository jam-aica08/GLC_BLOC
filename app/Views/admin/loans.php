<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Loans</h4>
        <p class="text-muted mb-0 small">Review and manage member loan applications.</p>
    </div>
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

<!-- Filter Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted small me-1"><i class="fa-solid fa-filter me-1"></i>Filter by status:</span>
            <a href="<?= base_url('admin/loans') ?>"
               class="btn btn-sm <?= (!isset($filterStatus) || $filterStatus === '') ? 'text-white' : 'btn-outline-secondary' ?>"
               style="<?= (!isset($filterStatus) || $filterStatus === '') ? 'background:#1a1a2e;' : '' ?>">All</a>
            <a href="<?= base_url('admin/loans?status=Pending') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Pending') ? 'btn-warning' : 'btn-outline-warning' ?>">Pending</a>
            <a href="<?= base_url('admin/loans?status=Approved') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Approved') ? 'btn-success' : 'btn-outline-success' ?>">Approved</a>
            <a href="<?= base_url('admin/loans?status=Active') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Active') ? 'btn-primary' : 'btn-outline-primary' ?>">Active</a>
            <a href="<?= base_url('admin/loans?status=Rejected') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Rejected') ? 'btn-danger' : 'btn-outline-danger' ?>">Rejected</a>
            <a href="<?= base_url('admin/loans?status=Paid') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Paid') ? 'btn-secondary' : 'btn-outline-secondary' ?>">Paid</a>
        </div>
    </div>
</div>

<!-- Loans Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-hand-holding-dollar me-2" style="color:#f0a500;"></i>Loan Applications</span>
        <span class="badge bg-secondary"><?= count($loans ?? []) ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Loan ID</th>
                        <th class="py-3">Account #</th>
                        <th class="py-3">Member</th>
                        <th class="py-3">Amount</th>
                        <th class="py-3">Interest Rate</th>
                        <th class="py-3">Term</th>
                        <th class="py-3">Total Payable</th>
                        <th class="py-3">Paid</th>
                        <th class="py-3">Balance</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Applied Date</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($loans)): ?>
                        <?php foreach ($loans as $loan): ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($loan['id'] ?? '-') ?></code></td>
                            <td class="py-3"><code><?= esc($loan['account_number'] ?? '-') ?></code></td>
                            <td class="py-3">
                                <div class="fw-semibold"><?= esc($loan['member_name'] ?? '-') ?></div>
                                <small class="text-muted"><?= esc($loan['member_number'] ?? '') ?></small>
                            </td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($loan['loan_amount'] ?? 0, 2) ?></td>
                            <td class="py-3"><?= esc($loan['interest_rate'] ?? '0') ?>%</td>
                            <td class="py-3"><?= esc($loan['term_months'] ?? '-') ?> mo.</td>
                            <?php $loanBalance = max(0, (float) $loan['total_payable'] - (float) $loan['total_paid']); ?>
                            <td class="py-3">&#8369;<?= number_format($loan['total_payable'] ?? 0, 2) ?></td>
                            <td class="py-3 text-success">&#8369;<?= number_format($loan['total_paid'] ?? 0, 2) ?></td>
                            <td class="py-3 fw-semibold <?= $loanBalance > 0 ? 'text-danger' : 'text-success' ?>">&#8369;<?= number_format($loanBalance, 2) ?></td>
                            <td class="py-3">
                                <?php $status = $loan['status'] ?? 'Pending'; ?>
                                <span class="badge
                                    <?= ($status === 'Active') ? 'bg-primary' :
                                        (($status === 'Approved') ? 'bg-success' :
                                        (($status === 'Rejected') ? 'bg-danger' :
                                        (($status === 'Paid') ? 'bg-secondary' : 'bg-warning text-dark'))) ?>">
                                    <?= esc($status) ?>
                                </span>
                            </td>
                            <td class="py-3 text-muted"><?= esc($loan['applied_date'] ?? '-') ?></td>
                            <td class="py-3 text-center">
                                <?php if ($status === 'Pending'): ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/loans/approve/' . ($loan['id'] ?? 0)) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success"
                                                    onclick="return confirm('Approve this loan application?')">
                                                <i class="fa-solid fa-check me-1"></i>Approve
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/loans/reject/' . ($loan['id'] ?? 0)) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Reject this loan application?')">
                                                <i class="fa-solid fa-xmark me-1"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif ($status === 'Active'): ?>
                                    <span class="text-success small"><i class="fa-solid fa-circle-check me-1"></i>Active</span>
                                <?php elseif ($status === 'Paid'): ?>
                                    <span class="text-muted small"><i class="fa-solid fa-check-double me-1"></i>Paid Off</span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-hand-holding-dollar fa-2x mb-3 d-block opacity-25"></i>
                                No loan applications found for the selected filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
