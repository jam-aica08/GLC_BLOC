<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-calendar-alt me-2 text-warning"></i>Loan #<?= (int) $loan['loan_id'] ?></h4>
        <p class="text-muted small mb-0">
            ₱<?= number_format($loan['loan_amount'] ?? 0, 2) ?> at <?= esc($loan['interest_rate'] ?? '') ?>% ·
            <?= (int) ($loan['loan_term_months'] ?? 0) ?> months · <?= esc($loan['account_number'] ?? '') ?>
        </p>
    </div>
    <a href="<?= base_url('member/loans') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Back to Loans
    </a>
</div>

<?= view('partials/flash') ?>

<?php $canPay = in_array($loan['loan_status'] ?? '', ['Active', 'Approved'], true); ?>

<form method="post" action="<?= base_url('member/loans/' . (int) $loan['loan_id'] . '/pay') ?>" id="payForm">
    <?= csrf_field() ?>

    <?= view('partials/installment_schedule', ['schedule' => $schedule, 'canSelect' => $canPay]) ?>

    <?php if ($canPay && $schedule['summary']['current'] && ! $schedule['summary']['has_pending']): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="fas fa-money-bill me-2"></i>Make a Payment</div>
        <div class="card-body">
            <p class="small text-muted mb-3">Tick <strong>Month <?= (int) $schedule['summary']['current']['installment_number'] ?></strong> in the table above, then enter how much you are paying.</p>
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="payAmount" class="form-label fw-semibold small">Amount to pay (₱)</label>
                    <input type="number" name="amount_paid" id="payAmount" step="0.01" min="0.01" class="form-control" required disabled>
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
            <div class="alert alert-light border mt-3 mb-0 d-none" id="payMessage" role="status" aria-live="polite"></div>
            <button type="submit" class="btn btn-warning mt-3" id="paySubmit" disabled>
                <i class="fas fa-paper-plane me-1"></i>Submit Payment
            </button>
            <div class="form-text">Your payment is applied after it is approved by the administrators.</div>
        </div>
    </div>
    <?php endif; ?>
</form>

<script>
(function () {
    const box = document.getElementById('payThisMonth');
    const amount = document.getElementById('payAmount');
    const submit = document.getElementById('paySubmit');
    const message = document.getElementById('payMessage');
    if (!box || !amount) return;

    const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const owed = parseFloat(box.dataset.owed);
    const balance = parseFloat(box.dataset.balance);
    const month = box.dataset.month;
    const nextMonth = box.dataset.nextMonth;

    function describe() {
        const value = parseFloat(amount.value);
        if (!box.checked || !(value > 0)) { message.classList.add('d-none'); return; }
        let text;
        if (value > balance) {
            text = 'This is more than your remaining balance of ' + peso(balance) + '.';
        } else if (value < owed) {
            const rest = owed - value;
            text = 'You are paying ' + peso(value) + '. The remaining ' + peso(rest) +
                (nextMonth ? ' will be added to Month ' + nextMonth + '.' : ' stays as your remaining balance.');
        } else if (value > owed) {
            text = 'You are paying ' + peso(value) + '. Month ' + month + ' will be fully paid and the extra ' +
                peso(value - owed) + ' goes to the next month(s).';
        } else {
            text = 'You are paying ' + peso(value) + '. Month ' + month + ' will be fully paid.';
        }
        message.textContent = text;
        message.classList.remove('d-none');
    }

    box.addEventListener('change', function () {
        amount.disabled = !box.checked;
        submit.disabled = !box.checked;
        amount.max = balance.toFixed(2);
        amount.value = box.checked ? parseFloat(box.dataset.dueNow).toFixed(2) : '';
        describe();
    });
    amount.addEventListener('input', describe);
    document.getElementById('payForm').addEventListener('submit', function () {
        submit.disabled = true;
        submit.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting…';
    });
})();
</script>

<?= $this->endSection() ?>
