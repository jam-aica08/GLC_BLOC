<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-calendar-alt me-2 text-warning"></i>Loan Installments</h4>
        <p class="text-muted small mb-0">
            Loan #<?= esc($loan['loan_id'] ?? '') ?> —
            ₱<?= number_format($loan['loan_amount'] ?? 0, 2) ?> at <?= esc($loan['interest_rate'] ?? '') ?>% |
            <?= esc($loan['loan_term_months'] ?? '') ?> months
        </p>
    </div>
    <a href="<?= base_url('member/loans') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back to Loans
    </a>
</div>

<?= view('partials/flash') ?>

<?php $balance = max(0, $totalPayable - $totalPaid); ?>
<div class="row g-3 mb-4">
    <div class="col-sm-4"><div class="card text-center"><div class="card-body py-3">
        <div class="text-muted small">Total Payable</div>
        <div class="fw-bold fs-5">₱<?= number_format($totalPayable, 2) ?></div>
    </div></div></div>
    <div class="col-sm-4"><div class="card text-center"><div class="card-body py-3">
        <div class="text-muted small">Total Paid</div>
        <div class="fw-bold fs-5 text-success">₱<?= number_format($totalPaid, 2) ?></div>
    </div></div></div>
    <div class="col-sm-4"><div class="card text-center"><div class="card-body py-3">
        <div class="text-muted small">Remaining Balance</div>
        <div class="fw-bold fs-5 <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($balance, 2) ?></div>
    </div></div></div>
</div>

<?php $canPay = in_array($loan['loan_status'] ?? '', ['Active', 'Approved'], true); ?>
<form method="post" action="<?= base_url('member/loans/' . (int) ($loan['loan_id'] ?? 0) . '/pay') ?>" id="payForm">
    <?= csrf_field() ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:48px;"><span class="visually-hidden">Select</span></th>
                            <th>#</th>
                            <th>Due Date</th>
                            <th>Amount Due</th>
                            <th>Paid</th>
                            <th>Unpaid</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($installments)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No installments found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($installments as $inst): ?>
                            <?php
                                $unpaid    = max(0, round($inst['amount_due'] - $inst['amount_paid'], 2));
                                $isPending = in_array((int) $inst['installment_id'], $pendingIds, true);
                                $selectable = $canPay && $inst['payment_status'] !== 'Paid' && $unpaid > 0 && ! $isPending;
                                $c = ['Upcoming' => 'secondary', 'Due' => 'warning', 'Late' => 'danger', 'Paid' => 'success'][$inst['payment_status']] ?? 'secondary';
                            ?>
                            <tr>
                                <td>
                                    <?php if ($selectable): ?>
                                        <input class="form-check-input js-inst" type="checkbox" name="installment_ids[]"
                                               value="<?= (int) $inst['installment_id'] ?>" data-unpaid="<?= esc(number_format($unpaid, 2, '.', '')) ?>"
                                               aria-label="Pay installment <?= esc($inst['installment_number']) ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($inst['installment_number']) ?></td>
                                <td><?= esc($inst['due_date']) ?></td>
                                <td>₱<?= number_format($inst['amount_due'], 2) ?></td>
                                <td>₱<?= number_format($inst['amount_paid'], 2) ?></td>
                                <td>₱<?= number_format($unpaid, 2) ?></td>
                                <td>
                                    <?php if ($isPending): ?>
                                        <span class="badge bg-info text-dark">Payment pending approval</span>
                                    <?php else: ?>
                                        <span class="badge bg-<?= $c ?>"><?= esc($inst['payment_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($canPay): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="fas fa-money-bill me-2"></i>Pay Selected Installments</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="payAmount" class="form-label fw-semibold small">Amount (₱)</label>
                    <input type="number" name="amount_paid" id="payAmount" step="0.01" min="0.01" class="form-control" required disabled>
                    <div class="form-text">Filled in from the installments you tick. If you pay less, the unpaid part is added to your next installment.</div>
                </div>
                <div class="col-md-7">
                    <span class="form-label fw-semibold small d-block mb-2">Payment Method</span>
                    <?php foreach (\App\Controllers\Member::PAYMENT_METHODS as $i => $method): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="payment_method" id="method<?= $i ?>"
                                   value="<?= esc($method) ?>" required <?= $i === 0 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="method<?= $i ?>"><?= esc($method) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-warning mt-3" id="paySubmit" disabled>
                <i class="fas fa-paper-plane me-1"></i>Submit Payment
            </button>
        </div>
    </div>
    <?php endif; ?>
</form>

<script>
(function () {
    const boxes = document.querySelectorAll('.js-inst');
    const amount = document.getElementById('payAmount');
    const submit = document.getElementById('paySubmit');
    if (!amount) return;
    function update() {
        let total = 0, any = false;
        boxes.forEach(function (b) { if (b.checked) { total += parseFloat(b.dataset.unpaid); any = true; } });
        amount.disabled = !any;
        submit.disabled = !any;
        amount.max = any ? total.toFixed(2) : '';
        amount.value = any ? total.toFixed(2) : '';
    }
    boxes.forEach(function (b) { b.addEventListener('change', update); });
})();
</script>

<?= $this->endSection() ?>
