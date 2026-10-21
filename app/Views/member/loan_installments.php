<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-calendar-alt me-2 glc-icon"></i>Loan #<?= (int) $loan['loan_id'] ?></h4>
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

<div class="card shadow-sm">
    <div class="card-body" data-pay-container data-loan-id="<?= (int) $loan['loan_id'] ?>">
        <?= $panel ?>
    </div>
</div>

<?= view('partials/pay_panel_js') ?>

<?= $this->endSection() ?>
