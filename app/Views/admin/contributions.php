<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Contributions</h4>
        <p class="text-muted mb-0 small">Review and approve member contribution records.</p>
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

<?= view('partials/list_search', ['action' => 'admin/contributions', 'search' => $search, 'filterStatus' => $filterStatus, 'placeholder' => 'Search by member name, member #, account # or contribution ID']) ?>

<!-- Filter Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted small me-1"><i class="fa-solid fa-filter me-1"></i>Filter by approval status:</span>
            <a href="<?= base_url('admin/contributions') ?>"
               class="btn btn-sm <?= (!isset($filterStatus) || $filterStatus === '') ? 'text-white' : 'btn-outline-secondary' ?>"
               style="<?= (!isset($filterStatus) || $filterStatus === '') ? 'background:#1a1a2e;' : '' ?>">
                All
            </a>
            <a href="<?= base_url('admin/contributions?status=Pending') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Pending') ? 'btn-warning' : 'btn-outline-warning' ?>">
                Pending
            </a>
            <a href="<?= base_url('admin/contributions?status=Approved') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Approved') ? 'btn-success' : 'btn-outline-success' ?>">
                Approved
            </a>
            <a href="<?= base_url('admin/contributions?status=Rejected') ?>"
               class="btn btn-sm <?= (($filterStatus ?? '') === 'Rejected') ? 'btn-danger' : 'btn-outline-danger' ?>">
                Rejected
            </a>
        </div>
    </div>
</div>

<!-- Contributions Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-piggy-bank me-2" style="color:#f0a500;"></i>Contribution Records</span>
        <span class="badge bg-secondary"><?= (int) $page['total'] ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Contribution ID</th>
                        <th class="py-3">Account #</th>
                        <th class="py-3">Member Name</th>
                        <th class="py-3">Period</th>
                        <th class="py-3">Amount</th>
                        <th class="py-3">Payment Status</th>
                        <th class="py-3">Approval Status</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($contributions)): ?>
                        <?php foreach ($contributions as $contribution): ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($contribution['id'] ?? '-') ?></code></td>
                            <td class="py-3"><code><?= esc($contribution['account_number'] ?? '-') ?></code></td>
                            <td class="py-3">
                                <div class="fw-semibold"><?= esc($contribution['member_name'] ?? '-') ?></div>
                                <small class="text-muted"><?= esc($contribution['member_number'] ?? '') ?></small>
                            </td>
                            <td class="py-3 text-muted"><?= esc($contribution['contribution_period'] ?? '-') ?></td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($contribution['amount'] ?? 0, 2) ?></td>
                            <td class="py-3">
                                <?php $payStatus = $contribution['payment_status'] ?? 'Pending'; ?>
                                <span class="badge <?= ($payStatus === 'Approved') ? 'bg-success' : (($payStatus === 'Rejected') ? 'bg-danger' : 'bg-warning text-dark') ?>">
                                    <?= esc($payStatus) ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <?php $appStatus = $contribution['approval_status'] ?? 'Pending'; ?>
                                <?php if ($appStatus === 'Approved'): ?>
                                    <span class="badge bg-success">Approved</span>
                                    <?= view('partials/approved_by', ['name' => $contribution['approver_name'] ?? null, 'at' => $contribution['approved_at'] ?? null]) ?>
                                <?php elseif ($appStatus === 'Rejected'): ?>
                                    <span class="badge bg-danger">Rejected</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center">
                                <?php if ($appStatus === 'Pending'): ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/contributions/approve/' . ($contribution['id'] ?? 0)) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Approve this contribution?')">
                                                <i class="fa-solid fa-check me-1"></i>Approve
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/contributions/reject/' . ($contribution['id'] ?? 0)) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this contribution?')">
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
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-piggy-bank fa-2x mb-3 d-block opacity-25"></i>
                                No contributions found for the selected filter.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= view('partials/pager', ['page' => $page, 'action' => 'admin/contributions', 'query' => ['status' => $filterStatus, 'q' => $search]]) ?>
</div>

<?= $this->endSection() ?>
