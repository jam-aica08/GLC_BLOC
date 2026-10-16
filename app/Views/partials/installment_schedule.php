<?php
/**
 * Loan schedule: summary + month-by-month table. With $canPay the member can
 * tick months and type how much to pay for each.
 *
 * @var array $schedule LoanService::schedule() result
 * @var bool  $canPay   Show checkboxes, amount boxes and the submit button
 * @var int   $loanId
 */
$summary = $schedule['summary'];
$rows    = $schedule['rows'];
$canPay  = ($canPay ?? false) && $summary['current'] !== null;
$locked  = $summary['has_pending'];
$peso    = fn($n) => '₱' . number_format((float) $n, 2);
?>
<div class="row g-2 mb-3 text-center">
    <div class="col-4"><div class="border rounded p-2 h-100" style="border-color:#f0a500 !important;">
        <div class="text-muted small">Due now</div>
        <div class="fw-bold fs-5"><?= $summary['current'] ? $peso($summary['due_now']) : '—' ?></div>
    </div></div>
    <div class="col-4"><div class="border rounded p-2 h-100">
        <div class="text-muted small">Remaining balance</div>
        <div class="fw-bold fs-5 <?= $summary['balance'] > 0 ? 'text-danger' : 'text-success' ?>"><?= $peso($summary['balance']) ?></div>
        <div class="small text-muted">Paid <?= $peso($summary['total_paid']) ?> of <?= $peso($summary['total_payable']) ?></div>
    </div></div>
    <div class="col-4"><div class="border rounded p-2 h-100">
        <div class="text-muted small">Next due date</div>
        <div class="fw-bold fs-5"><?= $summary['next_due_date'] ? esc(date('M j, Y', strtotime($summary['next_due_date']))) : 'Fully paid' ?></div>
    </div></div>
</div>

<?php if ($summary['has_pending']): ?>
    <div class="alert alert-info py-2 small"><i class="fa-solid fa-hourglass-half me-2"></i>A payment is waiting for admin approval. The schedule updates once it is approved.</div>
<?php endif; ?>

<?php if ($canPay): ?>
<form method="post" action="<?= base_url('member/loans/' . (int) $loanId . '/pay') ?>" class="js-pay-form" <?= $locked ? 'data-locked' : '' ?>>
    <?= csrf_field() ?>
    <p class="small text-muted mb-2">Tick the month(s) you want to pay and type the amount. You can pay less, or pay later months early.
        Anything still unpaid after a due date is added to the next month.</p>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-sm align-middle mb-2" style="font-size:0.875rem;">
        <thead class="table-light">
            <tr>
                <?php if ($canPay): ?><th style="width:36px;"><span class="visually-hidden">Pay</span></th><?php endif; ?>
                <th>Month</th>
                <th>Due Date</th>
                <th class="text-end">Regular</th>
                <th class="text-end">Carried Over</th>
                <th class="text-end">Amount Due</th>
                <th class="text-end">Paid</th>
                <th class="text-end">Balance</th>
                <?php if ($canPay): ?><th class="text-end" style="width:140px;">Amount to Pay</th><?php endif; ?>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="10" class="text-center py-4 text-muted">No installments yet. They are created when the loan is approved.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): $id = (int) $row['installment_id']; ?>
                <tr class="<?= $row['is_current'] ? 'table-warning' : '' ?>">
                    <?php if ($canPay): ?>
                        <td>
                            <?php if ($row['is_open']): ?>
                                <input class="form-check-input js-pay-check" type="checkbox" name="installment_ids[]" value="<?= $id ?>"
                                       id="pay-<?= (int) $loanId ?>-<?= $id ?>" aria-label="Pay Month <?= (int) $row['installment_number'] ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><label for="pay-<?= (int) $loanId ?>-<?= $id ?>" class="mb-0">Month <?= (int) $row['installment_number'] ?></label></td>
                    <td><?= esc(date('M j, Y', strtotime($row['due_date']))) ?></td>
                    <td class="text-end"><?= $peso($row['regular']) ?></td>
                    <td class="text-end <?= $row['carried'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $row['carried'] > 0 ? '+ ' . $peso($row['carried']) : '—' ?></td>
                    <td class="text-end fw-semibold"><?= $peso($row['total_due']) ?></td>
                    <td class="text-end"><?= $row['amount_paid'] > 0 ? $peso($row['amount_paid']) : '—' ?></td>
                    <td class="text-end fw-semibold <?= $row['owed'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= $row['owed'] > 0 ? $peso($row['owed']) : '—' ?></td>
                    <?php if ($canPay): ?>
                        <td class="text-end">
                            <?php if ($row['is_open']): ?>
                                <input type="number" class="form-control form-control-sm text-end js-pay-amount" name="amounts[<?= $id ?>]"
                                       step="0.01" min="0.01" max="<?= esc(number_format($row['owed'], 2, '.', '')) ?>"
                                       data-owed="<?= esc(number_format($row['owed'], 2, '.', '')) ?>"
                                       data-month="<?= (int) $row['installment_number'] ?>"
                                       data-next-month="<?= esc($row['next_month'] ?? '') ?>"
                                       data-due="<?= esc(date('M j', strtotime($row['due_date']))) ?>"
                                       aria-label="Amount to pay for Month <?= (int) $row['installment_number'] ?>" disabled>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td><span class="badge <?= $row['badge'] ?> text-wrap text-start"><?= esc($row['label']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($canPay): ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top pt-3">
        <div>
            <div class="fw-semibold">Total to pay: <span class="fs-5 js-pay-total">₱0.00</span></div>
            <div class="small text-muted js-pay-note" aria-live="polite"></div>
        </div>
        <button type="submit" class="btn btn-warning js-pay-submit" disabled>
            <i class="fas fa-paper-plane me-1"></i>Submit Payment
        </button>
    </div>
    <div class="small text-muted mt-1">
        <?= $locked ? 'You can submit another payment once your previous payment is approved or rejected.'
                    : 'Payments are applied after approval by the administrators.' ?>
    </div>
</form>
<?php endif; ?>
