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
     style="background:rgba(26,26,46,0.06);border:1px solid rgba(26,26,46,0.15);color:#1a1a2e;border-radius:10px;">
    <i class="fa-solid fa-circle-info" style="color:#f0a500;"></i>
    <span class="small">
        Financial records require <strong>two separate approvals</strong> before they are posted to the blockchain ledger.
        1st Approver verifies the transaction; 2nd Approver gives final authorization.
    </span>
</div>

<!-- Financial Records Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-file-invoice-dollar me-2" style="color:#f0a500;"></i>Financial Records</span>
        <span class="badge bg-secondary"><?= count($financialRecords ?? []) ?> record(s)</span>
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
                        <th class="py-3">1st Approver</th>
                        <th class="py-3">2nd Approver</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($financialRecords)): ?>
                        <?php foreach ($financialRecords as $record): ?>
                        <?php
                            $approver1 = $record['approver1_name'] ?? $record['first_approver'] ?? null;
                            $approver2 = $record['approver2_name'] ?? $record['second_approver'] ?? null;
                            $approvalCount = intval($record['approval_count'] ?? 0);
                            // Determine status label
                            $isRejected = ($record['approval_status'] ?? '') === 'Rejected';
                            if ($isRejected) {
                                $statusLabel = 'Rejected';
                                $statusClass = 'bg-danger';
                            } elseif ($approvalCount >= 2) {
                                $statusLabel = 'Fully Approved';
                                $statusClass = 'bg-success';
                            } elseif ($approvalCount === 1) {
                                $statusLabel = 'Needs 2nd Approval';
                                $statusClass = 'bg-warning text-dark';
                            } else {
                                $statusLabel = 'Pending';
                                $statusClass = 'bg-secondary';
                            }
                            // Who can approve next
                            $currentUserRole = session()->get('role') ?? '';
                            $currentUserId = session()->get('user_id') ?? 0;
                            $canApprove1st = ! $isRejected && ($approvalCount === 0);
                            $canApprove2nd = ! $isRejected && ($approvalCount === 1 && intval($record['approver1_id'] ?? 0) !== intval($currentUserId));
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
                            <!-- 1st Approver -->
                            <td class="py-3">
                                <?php if ($approver1): ?>
                                    <div class="d-flex align-items-center gap-1">
                                        <i class="fa-solid fa-circle-check text-success small"></i>
                                        <span><?= esc($approver1) ?></span>
                                    </div>
                                    <small class="text-muted"><?= esc($record['approved_at_1'] ?? '') ?></small>
                                <?php else: ?>
                                    <span class="text-muted small">Not yet</span>
                                <?php endif; ?>
                            </td>
                            <!-- 2nd Approver -->
                            <td class="py-3">
                                <?php if ($approver2): ?>
                                    <div class="d-flex align-items-center gap-1">
                                        <i class="fa-solid fa-circle-check text-success small"></i>
                                        <span><?= esc($approver2) ?></span>
                                    </div>
                                    <small class="text-muted"><?= esc($record['approved_at_2'] ?? '') ?></small>
                                <?php elseif ($approvalCount === 1): ?>
                                    <span class="badge bg-warning text-dark small">
                                        <i class="fa-solid fa-clock me-1"></i>Awaiting
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">Not yet</span>
                                <?php endif; ?>
                            </td>
                            <!-- Status -->
                            <td class="py-3">
                                <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            </td>
                            <!-- Actions -->
                            <td class="py-3 text-center">
                                <?php if ($isRejected): ?>
                                    <span class="text-danger small"><i class="fa-solid fa-ban me-1"></i>Rejected</span>
                                <?php elseif ($approvalCount >= 2): ?>
                                    <span class="text-success small">
                                        <i class="fa-solid fa-circle-check me-1"></i>Complete
                                    </span>
                                <?php elseif ($canApprove1st): ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/financial-records/approve/' . $record['id']) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="approval_level" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-success"
                                                    onclick="return confirm('Submit 1st approval for this record?')">
                                                <i class="fa-solid fa-stamp me-1"></i>Approve
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/financial-records/reject/' . $record['id']) ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('Reject this financial record? This cannot be undone.')">
                                                <i class="fa-solid fa-xmark me-1"></i>Reject
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif ($canApprove2nd): ?>
                                    <form action="<?= base_url('admin/financial-records/approve/' . $record['id']) ?>" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="approval_level" value="2">
                                        <button type="submit" class="btn btn-sm btn-success"
                                                onclick="return confirm('Submit 2nd (final) approval for this record?')">
                                            <i class="fa-solid fa-check-double me-1"></i>Approve (Final)
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-file-invoice-dollar fa-2x mb-3 d-block opacity-25"></i>
                                No financial records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
