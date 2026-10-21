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

<?= view('partials/list_search', ['action' => 'admin/payments', 'search' => $search, 'filterStatus' => $filterStatus, 'placeholder' => 'Search by member name, member #, account # or loan ID']) ?>

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
        <span class="badge bg-secondary"><?= (int) $page['total'] ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Member</th>
                        <th class="py-3">Loan</th>
                        <th class="py-3 text-end">Amount</th>
                        <th class="py-3">For</th>
                        <th class="py-3">Date</th>
                        <th class="py-3 text-end">Loan Balance</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-money-bill-wave fa-2x mb-3 d-block opacity-25"></i>
                                No payment records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                            $status  = $payment['status'] ?? 'Pending';
                            $loanId  = (int) ($payment['loan_id'] ?? 0);
                            $balance = max(0, (float) $payment['total_payable'] - (float) $payment['total_paid']);
                            if ($status === 'Approved') {
                                $label = 'Paid'; $badge = 'bg-success';
                            } elseif ($status === 'Rejected') {
                                $label = 'Rejected'; $badge = 'bg-danger';
                            } else {
                                $label = 'Pending'; $badge = 'bg-warning text-dark';
                            }
                        ?>
                        <tr>
                            <td class="px-4 py-3">
                                <div class="fw-semibold"><?= esc($payment['member_name'] ?? '-') ?></div>
                                <small class="text-muted text-nowrap"><?= esc($payment['account_number'] ?? '') ?> · <?= esc($payment['member_number'] ?? '') ?></small>
                            </td>
                            <td class="py-3">
                                <a href="<?= base_url('admin/loans/' . $loanId . '/schedule') ?>" class="js-schedule"
                                   data-loan-id="<?= $loanId ?>" data-title="Loan #<?= $loanId ?> · <?= esc($payment['member_name'] ?? '') ?>"
                                   title="View schedule">#<?= $loanId ?></a>
                            </td>
                            <td class="py-3 text-end fw-semibold">&#8369;<?= number_format($payment['amount_paid'] ?? 0, 2) ?></td>
                            <td class="py-3 small"><?= esc($payment['months'] ?? '—') ?></td>
                            <td class="py-3 text-muted text-nowrap"><?= esc(date('M j, Y', strtotime($payment['payment_date']))) ?></td>
                            <td class="py-3 text-end <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">&#8369;<?= number_format($balance, 2) ?></td>
                            <td class="py-3">
                                <span class="badge <?= $badge ?>"><?= esc($label) ?></span>
                                <?php if ($status === 'Approved'): ?>
                                    <?= view('partials/approved_by', ['name' => $payment['approver_name'] ?? null, 'at' => $payment['approved_at'] ?? null]) ?>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center">
                                <?php if ($status === 'Pending'): ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/payments/approve/' . (int) $payment['id']) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success text-nowrap" onclick="return confirm('Approve this payment?')">
                                                <i class="fa-solid fa-check me-1"></i>Approve
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/payments/reject/' . (int) $payment['id']) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this payment?')">
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
                </tbody>
            </table>
        </div>
    </div>
    <?= view('partials/pager', ['page' => $page, 'action' => 'admin/payments', 'query' => ['status' => $filterStatus, 'q' => $search]]) ?>
</div>
<div class="small text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i>Loan balance is what is still unpaid on the loan; a pending payment is counted once it is approved. Click a loan number to see its schedule.</div>

<?= view('partials/schedule_modal') ?>

<?= $this->endSection() ?>
