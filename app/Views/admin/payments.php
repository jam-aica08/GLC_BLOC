<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Payments</h4>
        <p class="text-muted mb-0 small">Review and approve loan installment payments.</p>
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
            <a href="<?= base_url('admin/payments') ?>"
               class="btn btn-sm <?= (!isset($filterStatus) || $filterStatus === '') ? 'text-white' : 'btn-outline-secondary' ?>"
               style="<?= (!isset($filterStatus) || $filterStatus === '') ? 'background:#1a1a2e;' : '' ?>">All</a>
            <a href="<?= base_url('admin/payments?status=Pending') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Pending') ? 'btn-warning' : 'btn-outline-warning' ?>">Pending</a>
            <a href="<?= base_url('admin/payments?status=Approved') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Approved') ? 'btn-success' : 'btn-outline-success' ?>">Paid</a>
            <a href="<?= base_url('admin/payments?status=Rejected') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Rejected') ? 'btn-danger' : 'btn-outline-danger' ?>">Rejected</a>
        </div>
    </div>
</div>

<!-- Payments Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-money-bill-wave me-2" style="color:#f0a500;"></i>Payment Records</span>
        <span class="badge bg-secondary"><?= count($payments ?? []) ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Payment ID</th>
                        <th class="py-3">Loan ID</th>
                        <th class="py-3">Account #</th>
                        <th class="py-3">Member</th>
                        <th class="py-3">Amount Paid</th>
                        <th class="py-3">Payment Date</th>
                        <th class="py-3">Loan Total</th>
                        <th class="py-3">Total Paid</th>
                        <th class="py-3">Balance</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($payments)): ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($payment['id'] ?? '-') ?></code></td>
                            <td class="py-3"><a href="<?= base_url('admin/loans/' . (int) ($payment['loan_id'] ?? 0) . '/schedule') ?>"><code>#<?= esc($payment['loan_id'] ?? '-') ?></code></a></td>
                            <td class="py-3"><code><?= esc($payment['account_number'] ?? '-') ?></code></td>
                            <td class="py-3">
                                <div class="fw-semibold"><?= esc($payment['member_name'] ?? '-') ?></div>
                                <small class="text-muted"><?= esc($payment['member_number'] ?? '') ?></small>
                            </td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($payment['amount_paid'] ?? 0, 2) ?></td>
                            <td class="py-3 text-muted"><?= esc($payment['payment_date'] ?? '-') ?></td>
                            <?php $payBalance = max(0, (float) $payment['total_payable'] - (float) $payment['total_paid']); ?>
                            <td class="py-3">&#8369;<?= number_format($payment['total_payable'] ?? 0, 2) ?></td>
                            <td class="py-3 text-success">&#8369;<?= number_format($payment['total_paid'] ?? 0, 2) ?></td>
                            <td class="py-3 fw-semibold <?= $payBalance > 0 ? 'text-danger' : 'text-success' ?>">&#8369;<?= number_format($payBalance, 2) ?></td>
                            <td class="py-3">
                                <?php
                                    $status = $payment['status'] ?? 'Pending';
                                    if ($status === 'Approved') {
                                        $label = 'Paid'; $badge = 'bg-success';
                                    } elseif ($status === 'Rejected') {
                                        $label = 'Rejected'; $badge = 'bg-danger';
                                    } elseif (! empty($payment['first_approved_by'])) {
                                        $label = 'Needs 2nd Approval'; $badge = 'bg-info text-dark';
                                    } else {
                                        $label = 'Pending'; $badge = 'bg-warning text-dark';
                                    }
                                ?>
                                <span class="badge <?= $badge ?>"><?= esc($label) ?></span>
                                <?php if (! empty($payment['approver1_name']) && $status === 'Pending'): ?>
                                    <div class="small text-muted">1st: <?= esc($payment['approver1_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center">
                                <?php if ($status === 'Pending'): ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/payments/approve/' . ($payment['id'] ?? 0)) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success"
                                                    onclick="return confirm('Approve this payment?')">
                                                <i class="fa-solid fa-check me-1"></i><?= empty($payment['first_approved_by']) ? 'Approve' : 'Final Approve' ?>
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/payments/reject/' . ($payment['id'] ?? 0)) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Reject this payment?')">
                                                <i class="fa-solid fa-xmark me-1"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-money-bill-wave fa-2x mb-3 d-block opacity-25"></i>
                                No payment records found for the selected filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
