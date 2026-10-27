<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1 fw-bold">Withdrawals</h1>
        <p class="text-muted mb-0 small">Review members' requests to withdraw their contributions.</p>
    </div>
</div>

<?= view('partials/flash') ?>

<?= view('partials/list_search', ['action' => 'admin/withdrawals', 'search' => $search, 'filterStatus' => $filterStatus, 'placeholder' => 'Search by member name, member # or account #']) ?>

<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted small me-1"><i class="fa-solid fa-filter me-1"></i>Filter by status:</span>
            <a href="<?= base_url('admin/withdrawals') ?>" class="btn btn-sm <?= $filterStatus === '' ? 'btn-brand' : 'btn-outline-secondary' ?>">All</a>
            <a href="<?= base_url('admin/withdrawals?status=Pending') ?>" class="btn btn-sm <?= $filterStatus === 'Pending' ? 'btn-warning' : 'btn-outline-warning' ?>">Pending</a>
            <a href="<?= base_url('admin/withdrawals?status=Approved') ?>" class="btn btn-sm <?= $filterStatus === 'Approved' ? 'btn-success' : 'btn-outline-success' ?>">Approved</a>
            <a href="<?= base_url('admin/withdrawals?status=Rejected') ?>" class="btn btn-sm <?= $filterStatus === 'Rejected' ? 'btn-danger' : 'btn-outline-danger' ?>">Rejected</a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-money-bill-transfer me-2 glc-icon"></i>Withdrawal Requests</span>
        <span class="badge bg-secondary"><?= (int) $page['total'] ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Member</th>
                        <th class="py-3 text-end">Amount</th>
                        <th class="py-3">Method</th>
                        <th class="py-3">Requested</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($withdrawals)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-money-bill-transfer fa-2x mb-3 d-block opacity-25"></i>
                                No withdrawal requests found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($withdrawals as $w):
                        $badge = ['Approved' => 'bg-success', 'Rejected' => 'bg-danger'][$w['status']] ?? 'bg-warning text-dark';
                    ?>
                        <tr>
                            <td class="px-4 py-3">
                                <div class="fw-semibold"><?= esc($w['member_name']) ?></div>
                                <small class="text-muted text-nowrap"><?= esc($w['account_number']) ?> · <?= esc($w['member_number']) ?></small>
                                <?php if (! empty($w['reason'])): ?><div class="small text-muted">Reason: <?= esc($w['reason']) ?></div><?php endif; ?>
                            </td>
                            <td class="py-3 text-end fw-semibold">&#8369;<?= number_format((float) $w['amount'], 2) ?></td>
                            <td class="py-3"><?= esc($w['method'] ?? '—') ?></td>
                            <td class="py-3 text-muted text-nowrap"><?= esc(date('M j, Y g:i A', strtotime($w['created_at']))) ?></td>
                            <td class="py-3">
                                <span class="badge <?= $badge ?>"><?= esc($w['status']) ?></span>
                                <?php if ($w['status'] === 'Approved'): ?>
                                    <?= view('partials/approved_by', ['name' => $w['approver_name'] ?? null, 'at' => $w['approved_at'] ?? null]) ?>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center">
                                <?php if ($w['status'] === 'Pending'): ?>
                                    <?php if ($w['loans']): ?>
                                        <div class="small text-danger mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Outstanding loan
                                            <?= esc(implode(', ', array_map(fn($l) => '#' . $l['loan_id'], $w['loans']))) ?> — cannot be approved</div>
                                    <?php endif; ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <form action="<?= base_url('admin/withdrawals/' . (int) $w['withdrawal_id'] . '/approve') ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success text-nowrap" <?= $w['loans'] ? 'disabled' : '' ?>
                                                    onclick="return confirm('Approve this withdrawal of ₱<?= number_format((float) $w['amount'], 2) ?>? Release the money to the member by <?= esc($w['method'] ?? '', 'js') ?>.')">
                                                <i class="fa-solid fa-check me-1"></i>Approve
                                            </button>
                                        </form>
                                        <form action="<?= base_url('admin/withdrawals/' . (int) $w['withdrawal_id'] . '/reject') ?>" method="post">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this withdrawal?')">
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
    <?= view('partials/pager', ['page' => $page, 'action' => 'admin/withdrawals', 'query' => ['status' => $filterStatus, 'q' => $search]]) ?>
</div>
<div class="small text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i>
    Members may withdraw available contributions; a member with an outstanding loan cannot withdraw until every loan is fully paid.
    Approval is checked again against the policy and recorded in the blockchain ledger.</div>

<?= $this->endSection() ?>
