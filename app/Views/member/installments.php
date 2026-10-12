<?= $this->extend('layouts/member_main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Installment Schedule</h4>
        <p class="text-muted mb-0 small">
            Loan #<?= isset($loan) ? esc($loan['id']) : '—' ?> — View payment schedule and submit installment payments.
        </p>
    </div>
    <a href="<?= base_url('member/loans') ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-2"></i>Back to My Loans
    </a>
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

<!-- Loan Summary Card -->
<?php if (isset($loan)): ?>
<div class="card mb-4">
    <div class="card-header">
        <i class="fa-solid fa-file-contract me-2" style="color:#f0a500;"></i>Loan Summary
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Loan ID</div>
                <div class="fw-bold"><code>#<?= esc($loan['id']) ?></code></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Original Amount</div>
                <div class="fw-bold">&#8369;<?= number_format($loan['loan_amount'] ?? 0, 2) ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Outstanding Balance</div>
                <div class="fw-bold text-danger">&#8369;<?= number_format($loan['outstanding_balance'] ?? $loan['remaining_balance'] ?? 0, 2) ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Monthly Payment</div>
                <div class="fw-bold">&#8369;<?= number_format($loan['monthly_payment'] ?? 0, 2) ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Interest Rate</div>
                <div class="fw-bold"><?= esc($loan['interest_rate'] ?? '0') ?>% / month</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Term</div>
                <div class="fw-bold"><?= esc($loan['term_months'] ?? '-') ?> months</div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Approved Date</div>
                <div class="fw-bold"><?= esc($loan['approved_date'] ?? $loan['created_at'] ?? '-') ?></div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="text-muted small mb-1">Status</div>
                <div>
                    <?php $lStatus = $loan['status'] ?? 'Active'; ?>
                    <span class="badge <?= ($lStatus === 'Paid') ? 'bg-secondary' : 'bg-success' ?>">
                        <?= esc($lStatus) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Repayment Progress -->
        <?php
            $loanAmt = floatval($loan['loan_amount'] ?? 1);
            $remaining = floatval($loan['outstanding_balance'] ?? $loan['remaining_balance'] ?? $loanAmt);
            $paid = max(0, $loanAmt - $remaining);
            $pct = $loanAmt > 0 ? min(100, round(($paid / $loanAmt) * 100)) : 0;
        ?>
        <div class="mt-3">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span>Repayment Progress</span>
                <span>&#8369;<?= number_format($paid, 2) ?> paid (<?= $pct ?>%)</span>
            </div>
            <div class="progress" style="height:12px;border-radius:8px;">
                <div class="progress-bar bg-success" role="progressbar"
                     style="width:<?= $pct ?>%;border-radius:8px;"
                     aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Installment Schedule Table -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-calendar-days me-2" style="color:#f0a500;"></i>Payment Schedule</span>
        <span class="badge bg-secondary"><?= count($installments ?? []) ?> installment(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Month #</th>
                        <th class="py-3">Due Date</th>
                        <th class="py-3">Principal</th>
                        <th class="py-3">Interest</th>
                        <th class="py-3">Total Due</th>
                        <th class="py-3">Amount Paid</th>
                        <th class="py-3">Balance After</th>
                        <th class="py-3">Status</th>
                        <th class="py-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($installments)): ?>
                        <?php foreach ($installments as $inst): ?>
                        <?php $instStatus = $inst['status'] ?? 'Upcoming'; ?>
                        <tr class="<?= ($instStatus === 'Overdue') ? 'table-warning' : '' ?>">
                            <td class="px-4 py-3 fw-bold"><?= esc($inst['installment_number'] ?? $inst['month_number'] ?? '-') ?></td>
                            <td class="py-3"><?= esc($inst['due_date'] ?? '-') ?></td>
                            <td class="py-3">&#8369;<?= number_format($inst['principal'] ?? 0, 2) ?></td>
                            <td class="py-3 text-muted">&#8369;<?= number_format($inst['interest'] ?? 0, 2) ?></td>
                            <td class="py-3 fw-semibold">&#8369;<?= number_format($inst['total_due'] ?? $inst['amount_due'] ?? 0, 2) ?></td>
                            <td class="py-3">
                                <?php if (($inst['amount_paid'] ?? 0) > 0): ?>
                                    <span class="text-success fw-semibold">&#8369;<?= number_format($inst['amount_paid'], 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3">&#8369;<?= number_format($inst['balance_after'] ?? 0, 2) ?></td>
                            <td class="py-3">
                                <span class="badge
                                    <?= ($instStatus === 'Paid') ? 'bg-success' :
                                        (($instStatus === 'Overdue') ? 'bg-danger' :
                                        (($instStatus === 'Pending') ? 'bg-warning text-dark' : 'bg-secondary')) ?>">
                                    <?= esc($instStatus) ?>
                                </span>
                            </td>
                            <td class="py-3 text-center">
                                <?php if (in_array($instStatus, ['Upcoming', 'Overdue', 'Pending'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success"
                                            data-bs-toggle="modal"
                                            data-bs-target="#payInstallmentModal"
                                            data-installment-id="<?= esc($inst['id'] ?? '') ?>"
                                            data-due-date="<?= esc($inst['due_date'] ?? '') ?>"
                                            data-total-due="<?= esc($inst['total_due'] ?? $inst['amount_due'] ?? 0) ?>"
                                            data-month-number="<?= esc($inst['installment_number'] ?? $inst['month_number'] ?? '') ?>">
                                        <i class="fa-solid fa-money-bill me-1"></i>Pay
                                    </button>
                                <?php else: ?>
                                    <span class="text-success small"><i class="fa-solid fa-circle-check me-1"></i>Paid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-calendar-days fa-2x mb-3 d-block opacity-25"></i>
                                No installment schedule found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pay Installment Modal -->
<div class="modal fade" id="payInstallmentModal" tabindex="-1" aria-labelledby="payInstallmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a1a2e;">
                <h5 class="modal-title text-white" id="payInstallmentModalLabel">
                    <i class="fa-solid fa-money-bill me-2" style="color:#f0a500;"></i>Submit Installment Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= base_url('member/installments/pay') ?>" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="installment_id" id="modal_installment_id">
                <input type="hidden" name="loan_id" value="<?= esc($loan['id'] ?? '') ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Installment Month</span>
                            <strong id="modal_month_number">—</strong>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span class="text-muted small">Due Date</span>
                            <strong id="modal_due_date">—</strong>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span class="text-muted small">Amount Due</span>
                            <strong id="modal_total_due" class="text-danger">—</strong>
                        </div>
                        <input type="hidden" name="amount_due" id="modal_amount_due_input">
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label for="amount_paid" class="form-label fw-semibold small">Amount Paid (&#8369;) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">&#8369;</span>
                            <input type="number" class="form-control" id="amount_paid" name="amount_paid"
                                   placeholder="0.00" min="1" step="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="payment_method" class="form-label fw-semibold small">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="pay_method" name="payment_method" required>
                            <option value="" disabled selected>Select method</option>
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="reference_number" class="form-label fw-semibold small">Reference Number</label>
                        <input type="text" class="form-control" id="pay_reference" name="reference_number"
                               placeholder="Transaction reference (optional)">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:#1a1a2e;">
                        <i class="fa-solid fa-paper-plane me-2"></i>Submit Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Populate payment modal with installment data
document.addEventListener('DOMContentLoaded', function () {
    const payModal = document.getElementById('payInstallmentModal');
    if (payModal) {
        payModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const installmentId = button.getAttribute('data-installment-id');
            const dueDate = button.getAttribute('data-due-date');
            const totalDue = parseFloat(button.getAttribute('data-total-due') || 0);
            const monthNumber = button.getAttribute('data-month-number');

            document.getElementById('modal_installment_id').value = installmentId;
            document.getElementById('modal_month_number').textContent = 'Month ' + monthNumber;
            document.getElementById('modal_due_date').textContent = dueDate;
            document.getElementById('modal_total_due').textContent = '₱' + totalDue.toLocaleString('en-PH', {minimumFractionDigits: 2});
            document.getElementById('modal_amount_due_input').value = totalDue;
            document.getElementById('amount_paid').value = totalDue.toFixed(2);
            document.getElementById('amount_paid').min = 1;
        });
    }
});
</script>

<?= $this->endSection() ?>
