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

<!-- Member Search -->
<form method="get" action="<?= base_url('admin/members') ?>" class="mb-4" role="search">
    <div class="input-group">
        <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" name="q" class="form-control" aria-label="Search member accounts"
               placeholder="Search members by name, member #, account #, username or email">
        <button class="btn text-white" style="background:#1a1a2e;" type="submit">Search</button>
    </div>
</form>

<!-- Summary Cards Row -->
<div class="row g-3 mb-4">
    <!-- Total Members -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:56px;height:56px;background:rgba(26,26,46,0.08);">
                    <i class="fa-solid fa-users fa-lg" style="color:#1a1a2e;"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Members</div>
                    <div class="fw-bold fs-4"><?= number_format($totalMembers ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Contributions -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:56px;height:56px;background:rgba(25,135,84,0.1);">
                    <i class="fa-solid fa-piggy-bank fa-lg text-success"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Contributions <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-muted js-amount-toggle" title="Hide amount" aria-label="Hide amount"><i class="fa-solid fa-eye-slash"></i></button></div>
                    <div class="fw-bold fs-4 js-amount">&#8369;<?= number_format($totalContributions ?? 0, 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Loans -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:56px;height:56px;background:rgba(13,110,253,0.1);">
                    <i class="fa-solid fa-hand-holding-dollar fa-lg text-primary"></i>
                </div>
                <div>
                    <div class="text-muted small">Active Loans</div>
                    <div class="fw-bold fs-4"><?= number_format($activeLoans ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Approvals -->
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:56px;height:56px;background:rgba(255,193,7,0.15);">
                    <i class="fa-solid fa-clock fa-lg text-warning"></i>
                </div>
                <div>
                    <div class="text-muted small">Pending Approvals</div>
                    <div class="fw-bold fs-4"><?= number_format($pendingApprovals ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Row -->
<div class="row g-3">
    <!-- Recent Financial Records -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-file-invoice-dollar me-2" style="color:#f0a500;"></i>Recent Financial Records</span>
                <a href="<?= base_url('admin/financial-records') ?>" class="btn btn-sm"
                   style="background:#1a1a2e;color:#f0a500;font-size:0.78rem;">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.875rem;">
                        <thead style="background:#f8f9fa;">
                            <tr>
                                <th class="px-4 py-3">Record ID</th>
                                <th class="py-3">Type</th>
                                <th class="py-3">Amount</th>
                                <th class="py-3">Date</th>
                                <th class="py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentFinancialRecords)): ?>
                                <?php foreach ($recentFinancialRecords as $record): ?>
                                <tr>
                                    <td class="px-4 py-3"><code>#<?= esc($record['id']) ?></code></td>
                                    <td class="py-3"><?= esc($record['transaction_type'] ?? '-') ?></td>
                                    <td class="py-3 fw-semibold">&#8369;<?= number_format($record['amount'] ?? 0, 2) ?></td>
                                    <td class="py-3 text-muted"><?= esc($record['created_at'] ?? '-') ?></td>
                                    <td class="py-3">
                                        <?php
                                            $approvals = intval($record['approval_count'] ?? 0);
                                            if ($approvals >= 2):
                                        ?>
                                            <span class="badge bg-success">Approved</span>
                                        <?php elseif ($approvals === 1): ?>
                                            <span class="badge bg-warning text-dark">1st Approved</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        <i class="fa-solid fa-inbox me-2"></i>No financial records yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column -->
    <div class="col-lg-4 d-flex flex-column gap-3">
        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-clock-rotate-left me-2" style="color:#f0a500;"></i>Recent Activity
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (!empty($recentActivity)): ?>
                        <?php foreach (array_slice($recentActivity, 0, 6) as $activity): ?>
                        <li class="list-group-item border-0 px-4 py-3" style="font-size:0.85rem;">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fa-solid fa-circle-dot mt-1" style="color:#f0a500;font-size:0.6rem;"></i>
                                <div>
                                    <div><?= esc($activity['description'] ?? 'Activity recorded') ?></div>
                                    <small class="text-muted"><?= esc($activity['created_at'] ?? '') ?></small>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item border-0 px-4 py-3 text-muted text-center" style="font-size:0.85rem;">
                            No recent activity.
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-bolt me-2" style="color:#f0a500;"></i>Quick Links
            </div>
            <div class="card-body d-flex flex-column gap-2 py-3">
                <a href="<?= base_url('admin/contributions?status=Pending') ?>"
                   class="btn btn-sm d-flex align-items-center justify-content-between"
                   style="background:#fff7e6;border:1px solid #f0a500;color:#1a1a2e;">
                    <span><i class="fa-solid fa-piggy-bank me-2" style="color:#f0a500;"></i>Pending Contributions</span>
                    <?php if (($pendingContributions ?? 0) > 0): ?>
                        <span class="badge bg-warning text-dark"><?= $pendingContributions ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= base_url('admin/loans?status=Pending') ?>"
                   class="btn btn-sm d-flex align-items-center justify-content-between"
                   style="background:#e8f0fe;border:1px solid #0d6efd;color:#1a1a2e;">
                    <span><i class="fa-solid fa-hand-holding-dollar me-2 text-primary"></i>Pending Loans</span>
                    <?php if (($pendingLoans ?? 0) > 0): ?>
                        <span class="badge bg-primary"><?= $pendingLoans ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= base_url('admin/payments?status=Pending') ?>"
                   class="btn btn-sm d-flex align-items-center justify-content-between"
                   style="background:#e8faf0;border:1px solid #198754;color:#1a1a2e;">
                    <span><i class="fa-solid fa-money-bill-wave me-2 text-success"></i>Pending Payments</span>
                    <?php if (($pendingPayments ?? 0) > 0): ?>
                        <span class="badge bg-success"><?= $pendingPayments ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= base_url('admin/ledger') ?>"
                   class="btn btn-sm d-flex align-items-center"
                   style="background:#f8f9fa;border:1px solid #dee2e6;color:#1a1a2e;">
                    <i class="fa-solid fa-link me-2"></i>Blockchain Ledger
                </a>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
