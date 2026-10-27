<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

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

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1 fw-bold">Dashboard</h1>
        <p class="text-muted mb-0 small">Welcome back, <?= esc(session()->get('username') ?? 'Admin') ?>. Here's what's happening today.</p>
    </div>
    <span class="badge" style="background:var(--glc-gradient-dark);color:#fff; font-size: 0.8rem; padding: 8px 14px; border-radius: 8px;">
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
        <button class="btn btn-brand" type="submit">Search</button>
    </div>
</form>

<!-- Waiting for approval -->
<div class="row g-2 mb-4">
    <?php foreach ([
        ['contributions', 'Contributions', $pendingContributions, 'admin/contributions?status=Pending', 'fa-piggy-bank'],
        ['loans', 'Loans', $pendingLoans, 'admin/loans?status=Pending', 'fa-hand-holding-dollar'],
        ['payments', 'Loan Payments', $pendingPayments, 'admin/payments?status=Pending', 'fa-money-bill-wave'],
        ['requests', 'Account Requests', $pendingRequests, 'admin/members#account-requests', 'fa-inbox'],
    ] as [$key, $label, $count, $url, $icon]): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= base_url($url) ?>" class="card text-decoration-none h-100 <?= $count ? 'border-warning' : '' ?>" style="color:var(--glc-navy);"
               data-pending="<?= $key ?>" aria-label="Pending <?= $label ?>: <?= (int) $count ?>">
                <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="small"><i class="fa-solid <?= $icon ?> me-2 text-muted"></i>Pending <?= $label ?></span>
                    <span class="badge <?= $count ? 'bg-warning text-dark' : 'bg-light text-muted' ?> fs-6"><?= (int) $count ?></span>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<script>
// The "Pending …" tiles follow new requests without reloading. They read the
// bell's background check (every 20 seconds while the page is visible, at once
// on returning to the tab), which carries the pending counts, so the dashboard
// adds no requests of its own.
(function () {
    function paint(key, n) {
        var tile = document.querySelector('[data-pending="' + key + '"]');
        if (!tile) return;
        var badge = tile.querySelector('.badge');
        var was = parseInt(badge.textContent, 10) || 0;
        badge.textContent = n;
        badge.className = 'badge fs-6 ' + (n ? 'bg-warning text-dark' : 'bg-light text-muted');
        tile.classList.toggle('border-warning', n > 0);
        tile.setAttribute('aria-label', tile.textContent.trim().replace(/\s+\d+$/, '') + ': ' + n);
        if (n > was) { tile.classList.add('pending-bump'); setTimeout(function () { tile.classList.remove('pending-bump'); }, 1500); }
    }
    document.addEventListener('glc:feed', function (e) {
        var pending = e.detail && e.detail.pending;
        if (pending) Object.keys(pending).forEach(function (k) { paint(k, pending[k]); });
    });
})();
</script>
<style>
    /* A tile whose count just went up glows briefly (skipped when reduced motion is preferred). */
    @media (prefers-reduced-motion: no-preference) {
        .pending-bump { animation: pending-bump 1.5s ease-out; }
        @keyframes pending-bump { 0% { box-shadow: 0 0 0 4px rgba(250, 178, 25, .55); } 100% { box-shadow: 0 0 0 0 rgba(250, 178, 25, 0); } }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small"><i class="fa-solid fa-users me-1"></i><?= number_format($totalMembers) ?> member(s) · <?= number_format($totalAccounts) ?> account(s)</span>
</div>

<?= view('partials/dashboard_overview', ['d' => $overview, 'isAdmin' => true]) ?>

<?= $this->endSection() ?>
