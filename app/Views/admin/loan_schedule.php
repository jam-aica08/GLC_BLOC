<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Loan #<?= (int) $loan['loan_id'] ?> Schedule</h4>
        <p class="text-muted small mb-0">
            <?= esc($loan['member_name'] ?? '') ?> (<?= esc($loan['member_number'] ?? '') ?>) ·
            <?= esc($loan['account_number'] ?? '') ?> · ₱<?= number_format($loan['loan_amount'], 2) ?>
            at <?= esc($loan['interest_rate']) ?>% · <?= (int) $loan['loan_term_months'] ?> months · <?= esc($loan['loan_status']) ?>
        </p>
    </div>
    <a href="<?= base_url('admin/loans') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Loans</a>
</div>

<?= view('partials/installment_schedule', ['schedule' => $schedule, 'canSelect' => false]) ?>

<?= $this->endSection() ?>
