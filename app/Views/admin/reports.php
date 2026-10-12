<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 fw-bold"><i class="fas fa-file-alt me-2 text-warning"></i>Reports</h4>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="fas fa-users me-2"></i>Member Report</h6>
                <p class="text-muted small">Overview of all members and their account statuses.</p>
                <a href="/admin/members" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Members
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="fas fa-hand-holding-usd me-2"></i>Contributions Report</h6>
                <p class="text-muted small">Monthly contribution summary and approval status.</p>
                <a href="/admin/contributions" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Contributions
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="fas fa-money-bill-wave me-2"></i>Loan Report</h6>
                <p class="text-muted small">Active, pending and completed loan applications.</p>
                <a href="/admin/loans" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Loans
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="fas fa-receipt me-2"></i>Financial Records</h6>
                <p class="text-muted small">All financial transactions with dual-approval status.</p>
                <a href="/admin/financial-records" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Records
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="fas fa-link me-2"></i>Blockchain Ledger</h6>
                <p class="text-muted small">Tamper-evident hash-linked transaction ledger.</p>
                <a href="/admin/ledger" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Ledger
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h6 class="fw-bold"><i class="fas fa-chart-line me-2"></i>Analytics</h6>
                <p class="text-muted small">Equity growth trends and 3-month forecast.</p>
                <a href="/admin/analytics" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-eye me-1"></i>View Analytics
                </a>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
