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

<?= view('partials/dashboard_overview', ['d' => $overview, 'isAdmin' => false]) ?>

<!-- My accounts -->
<div class="card dash-tile mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="fa-solid fa-building-columns me-2" style="color:#f0a500;"></i>My Accounts</span>
        <a href="<?= base_url('member/profile') ?>" class="btn btn-sm btn-outline-secondary">Open another account</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th class="ps-3">Account #</th><th>Type</th><th>Opened</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($accounts)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No accounts yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td class="ps-3"><code><?= esc($acc['account_number']) ?></code></td>
                        <td><?= esc($acc['account_type']) ?></td>
                        <td><?= esc(date('M j, Y', strtotime($acc['account_start_date']))) ?></td>
                        <td><span class="badge bg-<?= $acc['account_status'] === 'Active' ? 'success' : ($acc['account_status'] === 'Pending' ? 'warning text-dark' : 'secondary') ?>"><?= esc($acc['account_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
