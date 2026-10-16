<?php
/**
 * Dashboard body shared by admins (whole cooperative) and members (own accounts).
 *
 * @var array $d       DashboardService::build() result
 * @var bool  $isAdmin
 */
$peso  = fn($n) => ((float) $n < 0 ? '−' : '') . '₱' . number_format(abs((float) $n), 2);
$who   = $isAdmin ? 'Cooperative' : 'My';
$eye   = '<button type="button" class="btn btn-sm btn-link p-0 ms-1 text-muted js-amount-toggle" title="Hide amount" aria-label="Hide amount"><i class="fa-solid fa-eye-slash"></i></button>';
$badge = ['Approved' => 'bg-success', 'Rejected' => 'bg-danger', 'Pending' => 'bg-warning text-dark', 'Needs 2nd approval' => 'bg-info text-dark'];
$icons = ['Contribution' => 'fa-piggy-bank', 'Loan Disbursement' => 'fa-hand-holding-dollar', 'Loan Payment' => 'fa-money-bill-wave', 'Dividend' => 'fa-coins'];
$loanLink = fn($loan) => $isAdmin ? base_url('admin/loans/' . (int) $loan['loan_id'] . '/schedule') : base_url('member/loans');
?>
<style>
    .dash-tile { border: 0; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .dash-tile .label { font-size: .8rem; color: #6c757d; }
    .dash-tile .value { font-size: 1.45rem; font-weight: 700; line-height: 1.2; }
    .dash-equity { background: #1a1a2e; color: #fff; }
    .dash-equity .label, .dash-equity .js-amount-toggle { color: #c9d1d9 !important; }
    .dash-equity .value { color: #f0a500; font-size: 1.7rem; }
    .amounts-hidden .js-amount-blur { filter: blur(7px); pointer-events: none; user-select: none; }
</style>

<!-- Equity and totals -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card dash-tile dash-equity h-100"><div class="card-body">
            <div class="label"><i class="fa-solid fa-scale-balanced me-1"></i><?= $who ?> Total Equity <?= $eye ?></div>
            <div class="value js-amount"><?= $peso($d['equity']) ?></div>
            <div class="small mt-2" style="color:#c9d1d9;">Contributions + dividends received</div>
            <div class="small" style="color:#c9d1d9;">Net after unpaid loans: <span class="fw-semibold text-white text-nowrap js-amount"><?= $peso($d['netPosition']) ?></span></div>
        </div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card dash-tile h-100" data-no-search><div class="card-body">
            <div class="label"><i class="fa-solid fa-piggy-bank me-1 text-success"></i>Total Contributions <?= $eye ?></div>
            <div class="value text-success js-amount"><?= $peso($d['contributions']) ?></div>
            <a class="small" href="<?= base_url(($isAdmin ? 'admin' : 'member') . '/contributions') ?>">View contributions</a>
        </div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card dash-tile h-100" data-no-search><div class="card-body">
            <div class="label"><i class="fa-solid fa-hand-holding-dollar me-1 text-primary"></i>Active Loans</div>
            <div class="value"><?= count($d['activeLoans']) ?></div>
            <div class="small text-muted">Unpaid balance: <span class="fw-semibold text-danger js-amount"><?= $peso($d['loanBalance']) ?></span></div>
        </div></div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card dash-tile h-100" data-no-search><div class="card-body">
            <div class="label"><i class="fa-solid fa-coins me-1 text-info"></i>Dividends Received</div>
            <div class="value js-amount"><?= $peso($d['dividends']) ?></div>
            <a class="small" href="<?= base_url(($isAdmin ? 'admin' : 'member') . '/dividends') ?>">View dividends</a>
        </div></div>
    </div>
</div>

<!-- Analytics + financial records summary -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card dash-tile h-100" data-no-search>
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fa-solid fa-chart-column me-2" style="color:#f0a500;"></i>Contributions per Month <span class="text-muted fw-normal small">(month paid for, last 12 months)</span></span>
                <a href="<?= base_url(($isAdmin ? 'admin' : 'member') . '/analytics') ?>" class="btn btn-sm btn-outline-secondary">Full analytics</a>
            </div>
            <div class="card-body">
                <div class="js-amount-blur" style="position:relative;height:240px;">
                    <canvas id="dashContribChart" role="img" aria-label="Approved contributions per month for the last 12 months"></canvas>
                </div>
                <details class="small mt-2 js-amount-blur">
                    <summary class="text-muted">Show as table</summary>
                    <table class="table table-sm mb-0 mt-2">
                        <thead><tr><th>Month</th><th class="text-end">Contributions</th></tr></thead>
                        <tbody>
                            <?php foreach ($d['chart']['labels'] as $i => $label): ?>
                                <tr><td><?= esc($label) ?></td><td class="text-end"><?= $peso($d['chart']['values'][$i]) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card dash-tile h-100" data-no-search>
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fa-solid fa-file-invoice-dollar me-2" style="color:#f0a500;"></i>Financial Records</span>
                <?php if ($isAdmin): ?><a href="<?= base_url('admin/financial-records') ?>" class="btn btn-sm btn-outline-secondary">View all</a><?php endif; ?>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($d['records'] as $r): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start py-3">
                        <div>
                            <div class="fw-semibold small"><i class="fa-solid <?= $icons[$r['type']] ?> me-2 text-muted"></i><?= esc($r['type']) ?></div>
                            <div class="small text-muted"><?= $r['approved_count'] ?> approved<?= $r['pending_count'] ? ' · <span class="text-warning-emphasis fw-semibold">' . $r['pending_count'] . ' pending</span>' : '' ?></div>
                        </div>
                        <span class="fw-semibold small js-amount"><?= $peso($r['approved_amount']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<!-- Active loans + recent activity -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card dash-tile" data-no-search>
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fa-solid fa-hand-holding-dollar me-2" style="color:#f0a500;"></i>Active Loans</span>
                <a href="<?= base_url(($isAdmin ? 'admin' : 'member') . '/loans') ?>" class="btn btn-sm btn-outline-secondary">All loans</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Loan</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Next Due</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($d['activeLoans'])): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No active loans.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($d['activeLoans'] as $loan): ?>
                            <?php $overdue = $loan['next_due_date'] && $loan['next_due_date'] < date('Y-m-d'); ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold">#<?= (int) $loan['loan_id'] ?> · <?= esc($isAdmin ? $loan['member_name'] : $loan['account_number']) ?></div>
                                    <div class="small text-muted"><?= esc($isAdmin ? $loan['account_number'] . ' · ' : '') ?><?= (int) $loan['loan_term_months'] ?> months</div>
                                </td>
                                <td class="text-end js-amount"><?= $peso($loan['total']) ?></td>
                                <td class="text-end text-success js-amount"><?= $peso($loan['paid']) ?></td>
                                <td class="text-end fw-semibold text-danger js-amount"><?= $peso($loan['balance']) ?></td>
                                <td class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>">
                                    <?= $loan['next_due_date'] ? esc(date('M j, Y', strtotime($loan['next_due_date']))) : '—' ?>
                                    <?php if ($overdue): ?><div class="small"><i class="fa-solid fa-triangle-exclamation me-1"></i>Past due</div><?php endif; ?>
                                </td>
                                <td class="pe-3 text-end"><a href="<?= $loanLink($loan) ?>" class="btn btn-sm btn-outline-dark"><?= $isAdmin ? 'Schedule' : 'View & Pay' ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card dash-tile" data-no-search>
            <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-clock-rotate-left me-2" style="color:#f0a500;"></i>Recent Activity</div>
            <ul class="list-group list-group-flush">
                <?php if (empty($d['recent'])): ?>
                    <li class="list-group-item text-center text-muted py-4 small">No activity yet.</li>
                <?php endif; ?>
                <?php foreach ($d['recent'] as $r): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start gap-2 py-2">
                        <div class="small">
                            <div class="fw-semibold"><i class="fa-solid <?= $icons[$r['record_type']] ?? 'fa-circle-dot' ?> me-2 text-muted"></i><?= esc($r['record_type']) ?></div>
                            <div class="text-muted"><?= esc($isAdmin ? ($r['member_name'] ?? '—') . ' · ' : '') ?><?= esc($r['account_number'] ?? '') ?> · <?= esc(date('M j, Y', strtotime($r['transaction_date'] ?: $r['created_at']))) ?></div>
                        </div>
                        <div class="text-end small">
                            <div class="fw-semibold js-amount"><?= $peso($r['amount']) ?></div>
                            <span class="badge <?= $badge[$r['status_label']] ?? 'bg-secondary' ?>"><?= esc($r['status_label']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<script>
(function () {
    const canvas = document.getElementById('dashContribChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: <?= json_encode($d['chart']['labels']) ?>,
            datasets: [{
                label: 'Contributions',
                data: <?= json_encode($d['chart']['values']) ?>,
                backgroundColor: '#f0a500',
                hoverBackgroundColor: '#c98a00',
                borderRadius: { topLeft: 4, topRight: 4 },
                borderSkipped: 'start',
                maxBarThickness: 28,
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => peso(ctx.parsed.y) } },
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#6c757d' } },
                y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(0,0,0,.06)' },
                     ticks: { color: '#6c757d', callback: v => '₱' + Number(v).toLocaleString('en-PH') } },
            },
        },
    });
})();
</script>
