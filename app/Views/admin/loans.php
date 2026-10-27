<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1 fw-bold">Loans</h1>
        <p class="text-muted mb-0 small">Review and manage member loan applications.</p>
    </div>
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

<?= view('partials/list_search', ['action' => 'admin/loans', 'search' => $search, 'filterStatus' => $filterStatus, 'placeholder' => 'Search by member name, member #, account # or loan ID']) ?>

<!-- Filter Tabs -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="text-muted small me-1"><i class="fa-solid fa-filter me-1"></i>Filter by status:</span>
            <a href="<?= base_url('admin/loans') ?>"
               class="btn btn-sm <?= (!isset($filterStatus) || $filterStatus === '') ? 'btn-brand' : 'btn-outline-secondary' ?>">All</a>
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
        <span><i class="fa-solid fa-hand-holding-dollar me-2 glc-icon"></i>Loan Applications</span>
        <span class="badge bg-secondary"><?= (int) $page['total'] ?> record(s)</span>
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
                                <?php elseif ($status === 'Active' || $status === 'Paid' || $status === 'Defaulted'): ?>
                                    <a href="<?= base_url('admin/loans/' . (int) $loan['id'] . '/schedule') ?>" class="btn btn-sm btn-outline-primary js-schedule"
                                       data-loan-id="<?= (int) $loan['id'] ?>" data-title="Loan #<?= (int) $loan['id'] ?> · <?= esc($loan['member_name'] ?? '') ?>">
                                        <i class="fa-solid fa-calendar-days me-1"></i>Schedule
                                    </a>
                                    <?php if (! empty($loan['offset'])): $o = $loan['offset']; ?>
                                        <div class="mt-1">
                                            <span class="badge bg-danger-subtle text-danger-emphasis">Missed payment<?= $o['overdue'] > 0 ? ' · ₱' . number_format($o['overdue'], 2) . ' overdue' : '' ?></span>
                                        </div>
                                        <?php if ($o['eligible']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger mt-1 text-nowrap"
                                                    data-bs-toggle="modal" data-bs-target="#offsetModal"
                                                    data-loan-id="<?= (int) $loan['id'] ?>"
                                                    data-member="<?= esc($loan['member_name'] ?? '') ?>"
                                                    data-balance="<?= esc(number_format($o['balance'], 2)) ?>"
                                                    data-overdue="<?= esc(number_format($o['overdue'], 2)) ?>"
                                                    data-max="<?= esc(number_format($o['max'], 2, '.', '')) ?>"
                                                    data-sources="<?= esc(implode(', ', array_map(fn($s) => $s['account_number'] . ' (₱' . number_format($s['available'], 2) . ')', $o['sources']))) ?>">
                                                <i class="fa-solid fa-scale-balanced me-1"></i>Apply Offset
                                            </button>
                                        <?php else: ?>
                                            <div class="small text-muted mt-1" style="max-width:14rem;margin:auto;">Offset: <?= esc($o['reason']) ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
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
    <?= view('partials/pager', ['page' => $page, 'action' => 'admin/loans', 'query' => ['status' => $filterStatus, 'q' => $search]]) ?>
</div>

<?= view('partials/schedule_modal') ?>

<!-- Loan offset (Loan Offset Policy) -->
<div class="modal fade" id="offsetModal" tabindex="-1" aria-labelledby="offsetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="offsetForm" method="post" action="" onsubmit="this.querySelector('[type=submit]').disabled = true;">
            <?= csrf_field() ?>
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="offsetModalLabel"><i class="fa-solid fa-scale-balanced me-2 glc-icon"></i>Apply Loan Offset</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-2">Settle loan <strong id="offsetLoan"></strong> of <strong id="offsetMember"></strong> from the member's contributions, because a repayment was missed.</p>
                <table class="table table-sm small mb-3">
                    <tr><th class="fw-normal text-muted">Overdue</th><td class="text-end" id="offsetOverdue"></td></tr>
                    <tr><th class="fw-normal text-muted">Loan balance</th><td class="text-end" id="offsetBalance"></td></tr>
                    <tr><th class="fw-normal text-muted">Taken from (in this order)</th><td class="text-end" id="offsetSources"></td></tr>
                </table>
                <label for="offsetAmount" class="form-label fw-semibold small">Amount to offset (₱)</label>
                <input type="number" id="offsetAmount" name="amount" class="form-control" min="0.01" step="0.01" required aria-describedby="offsetHelp">
                <div id="offsetHelp" class="form-text mb-3"></div>
                <label for="offsetConfirm" class="form-label fw-semibold small">Type OFFSET to confirm</label>
                <input type="text" id="offsetConfirm" name="confirm" class="form-control" pattern="OFFSET" required autocomplete="off">
                <p class="small text-muted mt-3 mb-0">The amount is applied to the loan's months, oldest first, and taken out of the member's contributions.
                    It is recorded in the blockchain ledger and the audit log, and the member is notified. It cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fa-solid fa-scale-balanced me-2"></i>Apply Offset</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('offsetModal').addEventListener('show.bs.modal', function (e) {
    const b = e.relatedTarget.dataset;
    document.getElementById('offsetForm').action = <?= json_encode(rtrim(base_url('admin/loans'), '/') . '/') ?> + encodeURIComponent(b.loanId) + '/offset';
    document.getElementById('offsetLoan').textContent = '#' + b.loanId;
    document.getElementById('offsetMember').textContent = b.member;
    document.getElementById('offsetOverdue').textContent = '₱' + b.overdue;
    document.getElementById('offsetBalance').textContent = '₱' + b.balance;
    document.getElementById('offsetSources').textContent = b.sources;
    const amount = document.getElementById('offsetAmount');
    amount.max = b.max; amount.value = b.max;
    document.getElementById('offsetHelp').textContent = 'Up to ₱' + Number(b.max).toLocaleString('en-PH', { minimumFractionDigits: 2 }) + ' (the smaller of the loan balance and the available contributions).';
    document.getElementById('offsetConfirm').value = '';
});
</script>

<?= $this->endSection() ?>
