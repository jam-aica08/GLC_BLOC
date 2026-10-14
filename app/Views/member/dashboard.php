<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

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

<div class="mb-4">
    <h4 class="fw-bold mb-1">Welcome, <?= esc(session()->get('username')) ?></h4>
    <p class="text-muted small mb-0">Your cooperative account summary.</p>
</div>

<?php foreach (($accounts ?? []) as $acc): ?>
    <?php if (($acc['account_status'] ?? '') === 'Pending'): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2" role="status">
            <i class="fa-solid fa-hourglass-half"></i>
            <div>
                Account <strong><?= esc($acc['account_number']) ?></strong> becomes active once
                <strong>₱<?= number_format($acc['standing']['join_balance'] ?? 0, 2) ?></strong> more is contributed (and approved).
                <a href="<?= base_url('member/contributions') ?>" class="alert-link">Contribute now</a>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="fas fa-university me-1"></i>Total Accounts</div>
                <div class="fw-bold fs-4"><?= count($accounts ?? []) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="fas fa-hand-holding-usd me-1"></i>Total Contributions <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-muted js-amount-toggle" title="Hide amount" aria-label="Hide amount"><i class="fa-solid fa-eye-slash"></i></button></div>
                <div class="fw-bold fs-4 text-success js-amount">₱<?= number_format($totalContributions ?? 0, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="fas fa-money-bill-wave me-1"></i>Active Loans</div>
                <div class="fw-bold fs-4 text-warning"><?= $activeLoansCount ?? 0 ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><i class="fas fa-coins me-1"></i>Dividends</div>
                <div class="fw-bold fs-4 text-info">₱<?= number_format($totalDividends ?? 0, 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold bg-white">My Accounts</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Account #</th><th>Type</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($accounts)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No accounts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td><?= esc($acc['account_number']) ?></td>
                                        <td><?= esc($acc['account_type']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $acc['account_status'] === 'Active' ? 'success' : 'secondary' ?>">
                                                <?= esc($acc['account_status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold bg-white">Recent Activity</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Type</th><th>Amount</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentRecords)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No recent transactions.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($recentRecords, 0, 5) as $r): ?>
                                    <tr>
                                        <td><?= esc($r['record_type']) ?></td>
                                        <td>₱<?= number_format($r['amount'], 2) ?></td>
                                        <td>
                                            <?php $c = ['Pending'=>'warning','Approved'=>'success','Rejected'=>'danger'][$r['approval_status']] ?? 'secondary'; ?>
                                            <span class="badge bg-<?= $c ?>"><?= esc($r['approval_status']) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
