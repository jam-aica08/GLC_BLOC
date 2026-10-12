<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-calendar-alt me-2 text-warning"></i>Loan Installments</h4>
        <p class="text-muted small mb-0">
            Loan #<?= esc($loan['loan_id'] ?? '') ?> —
            ₱<?= number_format($loan['loan_amount'] ?? 0, 2) ?> |
            <?= esc($loan['loan_term_months'] ?? '') ?> months
        </p>
    </div>
    <a href="/member/loans" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back to Loans
    </a>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Due Date</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Amount Due</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($installments)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No installments found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($installments as $inst): ?>
                            <tr>
                                <td><?= esc($inst['installment_number']) ?></td>
                                <td><?= esc($inst['due_date']) ?></td>
                                <td>₱<?= number_format($inst['principal_amount'], 2) ?></td>
                                <td>₱<?= number_format($inst['interest_amount'], 2) ?></td>
                                <td>₱<?= number_format($inst['amount_due'], 2) ?></td>
                                <td>₱<?= number_format($inst['amount_paid'], 2) ?></td>
                                <td>₱<?= number_format($inst['remaining_balance'], 2) ?></td>
                                <td>
                                    <?php $c = ['Upcoming'=>'secondary','Due'=>'warning','Late'=>'danger','Paid'=>'success'][$inst['payment_status']] ?? 'secondary'; ?>
                                    <span class="badge bg-<?= $c ?>"><?= esc($inst['payment_status']) ?></span>
                                </td>
                                <td>
                                    <?php if ($inst['payment_status'] !== 'Paid' && $inst['remaining_balance'] > 0): ?>
                                        <button class="btn btn-warning btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#payModal"
                                                data-installment="<?= $inst['installment_id'] ?>"
                                                data-amount="<?= $inst['remaining_balance'] ?>">
                                            Pay
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="/member/loans/<?= esc($loan['loan_id'] ?? '') ?>/pay">
                <?= csrf_field() ?>
                <input type="hidden" name="installment_ids[]" id="payInstallmentId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill me-2"></i>Submit Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount (₱)</label>
                        <input type="number" name="amount_paid" id="payAmount" step="0.01" min="0.01"
                               class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">Submit Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('payModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('payInstallmentId').value = btn.dataset.installment;
    document.getElementById('payAmount').value = btn.dataset.amount;
});
</script>

<?= $this->endSection() ?>
