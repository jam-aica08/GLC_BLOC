<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Reports</h4>
        <p class="text-muted mb-0 small">Cooperative financial and membership summary.</p>
    </div>
    <span class="badge" style="background:#1a1a2e;color:#f0a500;padding:8px 14px;border-radius:8px;">
        <i class="fa-regular fa-calendar me-1"></i><?= date('F d, Y') ?>
    </span>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:52px;height:52px;background:rgba(26,26,46,0.08);">
                    <i class="fa-solid fa-users fa-lg" style="color:#1a1a2e;"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Members</div>
                    <div class="fw-bold fs-4"><?= number_format($totalMembers ?? 0) ?></div>
                    <small class="text-success"><?= number_format($activeMembers ?? 0) ?> active</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:52px;height:52px;background:rgba(25,135,84,0.1);">
                    <i class="fa-solid fa-piggy-bank fa-lg text-success"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Contributions</div>
                    <div class="fw-bold fs-4">&#8369;<?= number_format($totalContributions ?? 0, 2) ?></div>
                    <small class="text-muted">approved only</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:52px;height:52px;background:rgba(13,110,253,0.1);">
                    <i class="fa-solid fa-hand-holding-dollar fa-lg text-primary"></i>
                </div>
                <div>
                    <div class="text-muted small">Loans Disbursed</div>
                    <div class="fw-bold fs-4">&#8369;<?= number_format($totalLoanAmount ?? 0, 2) ?></div>
                    <small class="text-primary"><?= number_format($activeLoans ?? 0) ?> active</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center gap-3 p-4">
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:52px;height:52px;background:rgba(240,165,0,0.15);">
                    <i class="fa-solid fa-coins fa-lg" style="color:#f0a500;"></i>
                </div>
                <div>
                    <div class="text-muted small">Dividends Paid</div>
                    <div class="fw-bold fs-4">&#8369;<?= number_format($totalDividends ?? 0, 2) ?></div>
                    <small class="text-muted">distributed</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Report Cards -->
<div class="row g-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header" style="background:#f8f9fa;">
                <i class="fas fa-users me-2" style="color:#1a1a2e;"></i><strong>Members</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr><td class="text-muted">Total Members</td><td class="fw-semibold"><?= number_format($totalMembers ?? 0) ?></td></tr>
                    <tr><td class="text-muted">Active Members</td><td class="fw-semibold text-success"><?= number_format($activeMembers ?? 0) ?></td></tr>
                    <tr><td class="text-muted">Inactive Members</td><td class="fw-semibold text-secondary"><?= number_format(($totalMembers ?? 0) - ($activeMembers ?? 0)) ?></td></tr>
                </table>
                <a href="<?= base_url('admin/members') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Members
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header" style="background:#f8f9fa;">
                <i class="fas fa-piggy-bank me-2 text-success"></i><strong>Contributions</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr><td class="text-muted">Total Approved</td><td class="fw-semibold">&#8369;<?= number_format($totalContributions ?? 0, 2) ?></td></tr>
                </table>
                <a href="<?= base_url('admin/contributions') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Contributions
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header" style="background:#f8f9fa;">
                <i class="fas fa-hand-holding-usd me-2 text-primary"></i><strong>Loans</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr><td class="text-muted">Total Disbursed</td><td class="fw-semibold">&#8369;<?= number_format($totalLoanAmount ?? 0, 2) ?></td></tr>
                    <tr><td class="text-muted">Total Repaid</td><td class="fw-semibold text-success">&#8369;<?= number_format($totalPayments ?? 0, 2) ?></td></tr>
                    <tr><td class="text-muted">Outstanding Balance</td><td class="fw-semibold text-danger">&#8369;<?= number_format(max(0, ($totalLoanAmount ?? 0) - ($totalPayments ?? 0)), 2) ?></td></tr>
                    <tr><td class="text-muted">Active Loans</td><td class="fw-semibold"><?= number_format($activeLoans ?? 0) ?></td></tr>
                </table>
                <a href="<?= base_url('admin/loans') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Loans
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header" style="background:#f8f9fa;">
                <i class="fas fa-receipt me-2" style="color:#f0a500;"></i><strong>Financial Records</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr><td class="text-muted">Contributions</td><td class="fw-semibold">&#8369;<?= number_format($totalContributions ?? 0, 2) ?></td></tr>
                    <tr><td class="text-muted">Loan Disbursements</td><td class="fw-semibold">&#8369;<?= number_format($totalLoanAmount ?? 0, 2) ?></td></tr>
                    <tr><td class="text-muted">Loan Payments</td><td class="fw-semibold">&#8369;<?= number_format($totalPayments ?? 0, 2) ?></td></tr>
                    <tr><td class="text-muted">Dividends</td><td class="fw-semibold">&#8369;<?= number_format($totalDividends ?? 0, 2) ?></td></tr>
                </table>
                <a href="<?= base_url('admin/financial-records') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Records
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header" style="background:#f8f9fa;">
                <i class="fas fa-link me-2 text-secondary"></i><strong>Blockchain Ledger</strong>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr><td class="text-muted">Total Ledger Entries</td><td class="fw-semibold"><?= number_format($ledgerCount ?? 0) ?></td></tr>
                    <tr><td class="text-muted">Valid Entries</td><td class="fw-semibold text-success"><?= number_format(($ledgerCount ?? 0) - ($tamperedCount ?? 0)) ?></td></tr>
                    <tr><td class="text-muted">Tampered Entries</td>
                        <td class="fw-semibold <?= ($tamperedCount ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= number_format($tamperedCount ?? 0) ?>
                        </td>
                    </tr>
                </table>
                <a href="<?= base_url('admin/ledger') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Ledger
                </a>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header" style="background:#f8f9fa;">
                <i class="fas fa-chart-line me-2 text-info"></i><strong>Analytics</strong>
            </div>
            <div class="card-body">
                <p class="text-muted small">Equity growth trends, OLS linear regression forecasting, and 3-month outlook.</p>
                <a href="<?= base_url('admin/analytics') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Analytics
                </a>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
