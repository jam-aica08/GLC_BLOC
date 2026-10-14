<?= $this->extend('layouts/member_main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">My Loans</h4>
        <p class="text-muted mb-0 small">View your loan history and apply for a new loan.</p>
    </div>
    <?php
        // Only allow new loan application if no active/pending loan exists
        $hasActiveLoan = false;
        foreach (($loans ?? []) as $l) {
            if (in_array($l['status'] ?? '', ['Pending', 'Approved', 'Active'])) {
                $hasActiveLoan = true;
                break;
            }
        }
    ?>
    <?php if (!$hasActiveLoan): ?>
        <button type="button" class="btn btn-sm text-white" style="background:#1a1a2e;"
                data-bs-toggle="modal" data-bs-target="#loanApplicationModal">
            <i class="fa-solid fa-plus me-2"></i>Apply for Loan
        </button>
    <?php else: ?>
        <span class="badge bg-warning text-dark" style="font-size:0.8rem;padding:8px 14px;">
            <i class="fa-solid fa-clock me-1"></i>You have an active/pending loan
        </span>
    <?php endif; ?>
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

<!-- Loans Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-hand-holding-dollar me-2" style="color:#f0a500;"></i>My Loan Applications</span>
        <span class="badge bg-secondary"><?= count($loans ?? []) ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Loan ID</th>
                        <th class="py-3">Amount</th>
                        <th class="py-3">Interest Rate</th>
                        <th class="py-3">Term</th>
                        <th class="py-3">Monthly Payment</th>
                        <th class="py-3">Outstanding</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Applied Date</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($loans)): ?>
                        <?php foreach ($loans as $loan): ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($loan['id']) ?></code></td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($loan['loan_amount'] ?? $loan['amount'] ?? 0, 2) ?></td>
                            <td class="py-3"><?= esc($loan['interest_rate'] ?? '0') ?>%</td>
                            <td class="py-3"><?= esc($loan['term_months'] ?? $loan['term'] ?? '-') ?> mo.</td>
                            <td class="py-3">&#8369;<?= number_format($loan['monthly_payment'] ?? 0, 2) ?></td>
                            <td class="py-3 text-danger fw-semibold">&#8369;<?= number_format($loan['outstanding_balance'] ?? $loan['remaining_balance'] ?? 0, 2) ?></td>
                            <td class="py-3">
                                <?php $status = $loan['status'] ?? 'Pending'; ?>
                                <span class="badge
                                    <?= ($status === 'Approved' || $status === 'Active') ? 'bg-success' :
                                        (($status === 'Rejected') ? 'bg-danger' :
                                        (($status === 'Paid') ? 'bg-secondary' : 'bg-warning text-dark')) ?>">
                                    <?= esc($status) ?>
                                </span>
                            </td>
                            <td class="py-3 text-muted small"><?= esc($loan['applied_date'] ?? $loan['created_at'] ?? '-') ?></td>
                            <td class="py-3 text-center">
                                <?php if (in_array($loan['status'] ?? '', ['Approved', 'Active'])): ?>
                                    <a href="<?= base_url('member/loans/' . $loan['id'] . '/installments') ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-calendar-days me-1"></i>Installments
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-hand-holding-dollar fa-2x mb-3 d-block opacity-25"></i>
                                No loan applications yet. Apply for a loan to get started.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Loan Application Modal -->
<div class="modal fade" id="loanApplicationModal" tabindex="-1" aria-labelledby="loanApplicationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a1a2e;">
                <h5 class="modal-title text-white" id="loanApplicationModalLabel">
                    <i class="fa-solid fa-hand-holding-dollar me-2" style="color:#f0a500;"></i>Loan Application
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= base_url('member/loans/apply') ?>" method="post">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <div class="alert" style="background:rgba(240,165,0,0.1);border:1px solid rgba(240,165,0,0.3);border-radius:8px;font-size:0.85rem;">
                        <i class="fa-solid fa-circle-info me-2" style="color:#f0a500;"></i>
                        Loan applications are subject to approval by the cooperative administrators.
                        Interest rates and terms are determined by the cooperative's policies.
                    </div>
                    <?php $rates = \App\Models\CooperativeAccountModel::LOAN_INTEREST_RATE; ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label for="loan_account_id" class="form-label fw-semibold small">Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="loan_account_id" name="account_id" required>
                                <?php foreach (($accounts ?? []) as $acc): if ($acc['account_status'] !== 'Active') continue; ?>
                                    <option value="<?= esc($acc['account_id']) ?>"
                                            data-rate="<?= esc(number_format($rates[$acc['account_type']] ?? 5, 2)) ?>">
                                        <?= esc($acc['account_number']) ?> (<?= esc($acc['account_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="loan_rate" class="form-label fw-semibold small">Interest Rate</label>
                            <div class="input-group">
                                <input type="text" class="form-control bg-light" id="loan_rate" readonly
                                       value="<?php $firstActive = current(array_filter($accounts ?? [], fn($a) => $a['account_status'] === 'Active')); ?><?= esc(number_format($rates[$firstActive['account_type'] ?? 'Basic'] ?? 5, 2)) ?>">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-12 form-text mt-1">Basic accounts: 5% interest. Pioneer accounts: 2% interest. Only active accounts can borrow.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="loan_amount" class="form-label fw-semibold small">Loan Amount (&#8369;) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">&#8369;</span>
                                <input type="number" class="form-control" id="loan_amount" name="loan_amount"
                                       placeholder="e.g. 10000.00" min="1" step="0.01" required
                                       value="<?= esc(old('loan_amount')) ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="loan_term_months" class="form-label fw-semibold small">Desired Term (months) <span class="text-danger">*</span></label>
                            <select class="form-select" id="loan_term_months" name="loan_term_months" required>
                                <option value="" disabled selected>Select term</option>
                                <option value="3" <?= (old('loan_term_months') == '3') ? 'selected' : '' ?>>3 months</option>
                                <option value="6" <?= (old('loan_term_months') == '6') ? 'selected' : '' ?>>6 months</option>
                                <option value="12" <?= (old('loan_term_months') == '12') ? 'selected' : '' ?>>12 months</option>
                                <option value="18" <?= (old('loan_term_months') == '18') ? 'selected' : '' ?>>18 months</option>
                                <option value="24" <?= (old('loan_term_months') == '24') ? 'selected' : '' ?>>24 months</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:#1a1a2e;">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('loan_account_id').addEventListener('change', function () {
    document.getElementById('loan_rate').value = this.selectedOptions[0].dataset.rate;
});
</script>

<?= $this->endSection() ?>
