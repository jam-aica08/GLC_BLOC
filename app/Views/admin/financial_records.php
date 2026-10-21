<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Financial Records</h4>
        <p class="text-muted mb-0 small">Two-tier approval system for cooperative financial transactions.</p>
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

<!-- Info Banner -->
<div class="alert d-flex align-items-center gap-2 mb-3"
     style="background:rgba(var(--glc-navy-rgb),0.06);border:1px solid rgba(var(--glc-navy-rgb),0.15);color:var(--glc-navy);border-radius:10px;">
    <i class="fa-solid fa-circle-info glc-icon"></i>
    <span class="small">
        A record is posted to the blockchain ledger once <strong>one administrator approves it</strong>.
        Dividends are approved as a whole distribution on the Dividends page.
    </span>
</div>

<?= view('partials/list_search', ['action' => 'admin/financial-records', 'search' => $search, 'filterStatus' => '', 'placeholder' => 'Search by member name, member #, account # or record ID']) ?>

<!-- Financial Records Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-file-invoice-dollar me-2 glc-icon"></i>Financial Records</span>
        <span class="badge bg-secondary"><?= (int) $page['total'] ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Record ID</th>
                        <th class="py-3">Type</th>
                        <th class="py-3">Member / Account</th>
                        <th class="py-3">Amount</th>
                        <th class="py-3">Date</th>
                        <th class="py-3">Approved By</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($financialRecords)): ?>
                        <?php foreach ($financialRecords as $record): ?>
                        <?php
                            $status     = $record['approval_status'] ?? 'Pending';
                            $isRejected = $status === 'Rejected';
                            [$statusLabel, $statusClass] = [
                                'Approved' => ['Approved', 'bg-success'],
                                'Rejected' => ['Rejected', 'bg-danger'],
                            ][$status] ?? ['Pending', 'bg-warning text-dark'];
                        ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($record['id']) ?></code></td>
                            <td class="py-3">
                                <span class="badge bg-light text-dark border"><?= esc($record['transaction_type'] ?? $record['type'] ?? '-') ?></span>
                            </td>
                            <td class="py-3">
                                <div class="fw-semibold"><?= esc($record['member_name'] ?? '-') ?></div>
                                <small class="text-muted"><?= esc($record['member_number'] ?? '') ?> · <?= esc($record['account_number'] ?? '') ?></small>
                            </td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($record['amount'] ?? 0, 2) ?></td>
                            <td class="py-3 text-muted small"><?= esc($record['transaction_date'] ?? $record['created_at'] ?? '-') ?></td>
                            <!-- Approved by -->
                            <td class="py-3">
                                <?php if ($status === 'Approved'): ?>
                                    <?= view('partials/approved_by', ['name' => $record['approver_name'] ?? null, 'at' => $record['approved_at'] ?? null]) ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <!-- Status -->
                            <td class="py-3">
                                <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            </td>
                            <!-- Actions -->
                            <td class="py-3 text-center">
                                <?php if ($status !== 'Pending'): ?>
                                    <span class="text-muted small">—</span>
                                <?php elseif (($record['transaction_type'] ?? '') === 'Dividend'): ?>
                                    <a href="<?= base_url('admin/dividends?year=' . date('Y', strtotime($record['transaction_date'] ?? $record['created_at'] ?? 'now'))) ?>" class="small">Approve on Dividends page</a>
                                <?php else: ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/financial-records/approve/' . $record['id']) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Approve this record?')">
                                                <i class="fa-solid fa-check me-1"></i>Approve
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/financial-records/reject/' . $record['id']) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this financial record? This cannot be undone.')">
                                                <i class="fa-solid fa-xmark me-1"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-file-invoice-dollar fa-2x mb-3 d-block opacity-25"></i>
                                No financial records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?= view('partials/pager', ['page' => $page, 'action' => 'admin/financial-records', 'query' => ['q' => $search]]) ?>
</div>

<?= $this->endSection() ?>
