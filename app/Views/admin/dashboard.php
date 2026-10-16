<?= $this->extend('layouts/main') ?>

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

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Dashboard</h4>
        <p class="text-muted mb-0 small">Welcome back, <?= esc(session()->get('username') ?? 'Admin') ?>. Here's what's happening today.</p>
    </div>
    <span class="badge" style="background-color: #1a1a2e; color: #f0a500; font-size: 0.8rem; padding: 8px 14px; border-radius: 8px;">
        <i class="fa-regular fa-calendar me-1"></i> <?= date('F d, Y') ?>
    </span>
</div>

<?php if (! empty($needsDbUpdate)): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-database"></i>
        <div>The database needs updating for the latest features.
            <a href="<?= base_url('admin/settings') ?>" class="alert-link">Open Settings → Apply Database Updates</a>.</div>
    </div>
<?php endif; ?>

<!-- Member Search -->
<form method="get" action="<?= base_url('admin/members') ?>" class="mb-4" role="search">
    <div class="input-group">
        <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" name="q" class="form-control" aria-label="Search member accounts"
               placeholder="Search members by name, member #, account #, username or email">
        <button class="btn text-white" style="background:#1a1a2e;" type="submit">Search</button>
    </div>
</form>

<!-- Waiting for approval -->
<div class="row g-2 mb-4">
    <?php foreach ([
        ['Contributions', $pendingContributions, 'admin/contributions?status=Pending', 'fa-piggy-bank'],
        ['Loans', $pendingLoans, 'admin/loans?status=Pending', 'fa-hand-holding-dollar'],
        ['Loan Payments', $pendingPayments, 'admin/payments?status=Pending', 'fa-money-bill-wave'],
        ['Account Requests', $pendingRequests, 'admin/members#account-requests', 'fa-inbox'],
    ] as [$label, $count, $url, $icon]): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= base_url($url) ?>" class="card text-decoration-none h-100 <?= $count ? 'border-warning' : '' ?>" style="color:#1a1a2e;">
                <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small"><i class="fa-solid <?= $icon ?> me-2 text-muted"></i>Pending <?= $label ?></span>
                    <span class="badge <?= $count ? 'bg-warning text-dark' : 'bg-light text-muted' ?> fs-6"><?= (int) $count ?></span>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small"><i class="fa-solid fa-users me-1"></i><?= number_format($totalMembers) ?> member(s) · <?= number_format($totalAccounts) ?> account(s)</span>
</div>

<?= view('partials/dashboard_overview', ['d' => $overview, 'isAdmin' => true]) ?>

<?= $this->endSection() ?>
