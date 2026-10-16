<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold">Loan #<?= (int) $loan['loan_id'] ?> Schedule</h4>
    <a href="<?= base_url('admin/loans') ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Loans</a>
</div>

<div class="card shadow-sm" data-no-search><div class="card-body">
<?= view('admin/loan_schedule_body', ['loan' => $loan, 'schedule' => $schedule]) ?>
</div></div>

<?= $this->endSection() ?>
