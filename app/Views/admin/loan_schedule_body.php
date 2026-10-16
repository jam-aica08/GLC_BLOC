<?php
/** Loan schedule contents, shown in the Loans pop-up and on the full schedule page. */
?>
<div class="mb-3">
    <span class="badge bg-<?= $loan['loan_status'] === 'Active' ? 'primary' : 'secondary' ?>"><?= esc($loan['loan_status']) ?></span>
</div>
<?= view('partials/installment_schedule', ['schedule' => $schedule, 'canPay' => false, 'loanId' => (int) $loan['loan_id']]) ?>
