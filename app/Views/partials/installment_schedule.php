<?php
/**
 * Loan schedule: summary box + month-by-month table.
 *
 * @var array $schedule  LoanService::schedule() result
 * @var bool  $canSelect Show the "pay this month" checkbox on the oldest unpaid month
 */
$summary   = $schedule['summary'];
$rows      = $schedule['rows'];
$canSelect = ($canSelect ?? false) && $summary['current'] !== null && ! $summary['has_pending'];
?>
<div class="row g-3 mb-4">
    <div class="col-sm-4"><div class="card text-center h-100 border-warning"><div class="card-body py-3">
        <div class="text-muted small">Due now</div>
        <div class="fw-bold fs-4"><?= $summary['current'] ? '₱' . number_format($summary['due_now'], 2) : '—' ?></div>
    </div></div></div>
    <div class="col-sm-4"><div class="card text-center h-100"><div class="card-body py-3">
        <div class="text-muted small">Remaining loan balance</div>
        <div class="fw-bold fs-4 <?= $summary['balance'] > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($summary['balance'], 2) ?></div>
        <div class="small text-muted">Paid ₱<?= number_format($summary['total_paid'], 2) ?> of ₱<?= number_format($summary['total_payable'], 2) ?></div>
    </div></div></div>
    <div class="col-sm-4"><div class="card text-center h-100"><div class="card-body py-3">
        <div class="text-muted small">Next due date</div>
        <div class="fw-bold fs-4"><?= $summary['next_due_date'] ? esc(date('M j, Y', strtotime($summary['next_due_date']))) : 'Fully paid' ?></div>
    </div></div></div>
</div>

<?php if ($summary['has_pending']): ?>
    <div class="alert alert-info"><i class="fa-solid fa-hourglass-half me-2"></i>A payment is waiting for admin approval. The schedule updates once it is approved.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <?php if ($canSelect): ?><th style="width:48px;"><span class="visually-hidden">Pay</span></th><?php endif; ?>
                        <th>Month</th>
                        <th>Due Date</th>
                        <th>Regular Amount</th>
                        <th>Carried Over</th>
                        <th>Amount Due</th>
                        <th>Paid</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No installments yet. They are created when the loan is approved.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr class="<?= $row['is_current'] ? 'table-warning' : '' ?>">
                            <?php if ($canSelect): ?>
                                <td>
                                    <?php if ($row['is_current']): ?>
                                        <input class="form-check-input" type="checkbox" id="payThisMonth" name="installment_id"
                                               value="<?= (int) $row['installment_id'] ?>"
                                               data-owed="<?= esc(number_format($row['owed'], 2, '.', '')) ?>"
                                               data-due-now="<?= esc(number_format($summary['due_now'], 2, '.', '')) ?>"
                                               data-balance="<?= esc(number_format($summary['balance'], 2, '.', '')) ?>"
                                               data-month="<?= (int) $row['installment_number'] ?>"
                                               data-next-month="<?= esc($row['next_month'] ?? '') ?>"
                                               aria-label="Pay Month <?= (int) $row['installment_number'] ?>">
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>Month <?= (int) $row['installment_number'] ?></td>
                            <td><?= esc(date('M j, Y', strtotime($row['due_date']))) ?></td>
                            <td>₱<?= number_format($row['regular'], 2) ?></td>
                            <td class="<?= $row['carried'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= $row['carried'] > 0 ? '+ ₱' . number_format($row['carried'], 2) : '—' ?>
                            </td>
                            <td class="fw-semibold">₱<?= number_format($row['total_due'], 2) ?></td>
                            <td><?= $row['amount_paid'] > 0 ? '₱' . number_format($row['amount_paid'], 2) : '—' ?></td>
                            <td><span class="badge <?= $row['badge'] ?>"><?= esc($row['label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
