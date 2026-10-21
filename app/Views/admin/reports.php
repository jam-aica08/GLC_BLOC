<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
$peso     = fn($n) => '₱' . number_format((float) $n, 2);
$query    = array_filter(['period' => $f['period'], 'from' => $f['from'], 'to' => $f['to'], 'category' => $f['category'], 'type' => $f['type'], 'status' => $f['status']], fn($v) => $v !== '');
$types    = $f['type'] !== '' ? [$f['type']] : $f['types'];
$statuses = $f['status'] !== '' ? [$f['status']] : ['Approved', 'Pending', 'Rejected'];
$titles   = ['summary' => 'Financial summary', 'transactions' => 'Transaction list', 'membership' => 'Membership and accounts', 'ledger' => 'Ledger integrity'];
?>
<style>
    .report { max-width: 980px; }
    .report h2 { font-size: 1.15rem; font-weight: 700; margin: 0 0 .75rem; }
    .report .table { font-size: .9rem; }
    .report .table th { font-weight: 600; }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    @media print {
        #sidebar, #top-navbar, .no-print { display: none !important; }
        #main-content { margin: 0 !important; }
        .report { max-width: none; }
    }
</style>

<div class="report">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">Reports</h4>
            <p class="mb-0 small text-muted"><?= esc($titles[$f['category']]) ?> · <?= esc(date('M j, Y', strtotime($f['from']))) ?> to <?= esc(date('M j, Y', strtotime($f['to']))) ?> · generated <?= date('M j, Y g:i A') ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
            <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('admin/reports/export?' . http_build_query($query)) ?>"><i class="fa-solid fa-file-csv me-1"></i>Download CSV</a>
        </div>
    </div>

    <form method="get" action="<?= base_url('admin/reports') ?>" class="border rounded p-3 mb-4 no-print" id="report-filters" aria-label="Report filters">
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-lg-3">
                <label for="r_period" class="form-label small fw-semibold mb-1">Reporting period</label>
                <select id="r_period" name="period" class="form-select form-select-sm">
                    <?php foreach (['month' => 'This month', 'year' => 'This year', '30days' => 'Last 30 days', 'all' => 'All time', 'custom' => 'Custom dates'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $f['period'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label for="r_from" class="form-label small fw-semibold mb-1">From</label>
                <input type="date" id="r_from" name="from" class="form-control form-control-sm" value="<?= esc($f['from']) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label for="r_to" class="form-label small fw-semibold mb-1">To</label>
                <input type="date" id="r_to" name="to" class="form-control form-control-sm" value="<?= esc($f['to']) ?>">
            </div>
            <div class="col-sm-6 col-lg-3">
                <label for="r_category" class="form-label small fw-semibold mb-1">Report category</label>
                <select id="r_category" name="category" class="form-select form-select-sm">
                    <?php foreach ($titles as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <label for="r_type" class="form-label small fw-semibold mb-1">Type</label>
                <select id="r_type" name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($f['types'] as $t): ?><option value="<?= esc($t) ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <label for="r_status" class="form-label small fw-semibold mb-1">Status</label>
                <select id="r_status" name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['Approved', 'Pending', 'Rejected'] as $st): ?><option value="<?= $st ?>" <?= $f['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <noscript><button class="btn btn-sm btn-dark mt-3">Update report</button></noscript>
    </form>

    <!-- Totals by type and status, for every category -->
    <section class="mb-4" aria-labelledby="h-totals">
        <h2 id="h-totals">Totals by transaction type</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr><th>Type</th><?php foreach ($statuses as $st): ?><th class="num"><?= $st ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php $colTotals = []; ?>
                    <?php foreach ($types as $t): ?>
                        <tr>
                            <td><?= esc($t) ?></td>
                            <?php foreach ($statuses as $st): $cell = $summary[$t][$st] ?? ['count' => 0, 'amount' => 0];
                                $colTotals[$st]['count']  = ($colTotals[$st]['count'] ?? 0) + $cell['count'];
                                $colTotals[$st]['amount'] = ($colTotals[$st]['amount'] ?? 0) + $cell['amount']; ?>
                                <td class="num"><?= $peso($cell['amount']) ?> <span class="text-muted small">(<?= $cell['count'] ?>)</span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold"><td>Total</td><?php foreach ($statuses as $st): ?><td class="num"><?= $peso($colTotals[$st]['amount'] ?? 0) ?> <span class="text-muted small">(<?= $colTotals[$st]['count'] ?? 0 ?>)</span></td><?php endforeach; ?></tr>
                </tfoot>
            </table>
        </div>
        <p class="small text-muted mb-0">Amounts in pesos, number of records in brackets. Dates use each record's transaction date.</p>
    </section>

    <?php if ($f['category'] === 'transactions'): ?>
        <section aria-labelledby="h-list">
            <h2 id="h-list">Transactions</h2>
            <?php if (empty($records)): ?>
                <p class="text-muted">No transactions match these filters.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Date</th><th>Record</th><th>Type</th><th>Member</th><th>Account</th><th>Status</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td class="text-nowrap"><?= esc(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
                                    <td>#<?= (int) $r['financial_record_id'] ?></td>
                                    <td><?= esc($r['record_type']) ?></td>
                                    <td><?= esc($r['member_name'] ?? '—') ?> <span class="text-muted small"><?= esc($r['member_number'] ?? '') ?></span></td>
                                    <td><?= esc($r['account_number'] ?? '—') ?></td>
                                    <td><?= esc($r['approval_status']) ?></td>
                                    <td class="num"><?= $peso($r['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($records) === 500): ?><p class="small text-muted">Showing the latest 500. Download the CSV for every row.</p><?php endif; ?>
            <?php endif; ?>
        </section>
    <?php elseif ($f['category'] === 'membership'): ?>
        <section aria-labelledby="h-members">
            <h2 id="h-members">Membership and accounts</h2>
            <table class="table table-sm" style="max-width:560px;">
                <tbody>
                    <tr><td>Members</td><td class="num"><?= $membership['members'] ?></td></tr>
                    <tr><td>Active members</td><td class="num"><?= $membership['active_members'] ?></td></tr>
                    <tr><td>Inactive or suspended members</td><td class="num"><?= $membership['members'] - $membership['active_members'] ?></td></tr>
                    <tr><td>Members who joined in this period</td><td class="num"><?= $membership['new_members'] ?></td></tr>
                    <tr><td>Accounts opened in this period</td><td class="num"><?= $membership['opened'] ?></td></tr>
                </tbody>
            </table>
            <h2 class="mt-4">Accounts by type and status</h2>
            <table class="table table-sm" style="max-width:560px;">
                <thead><tr><th>Type</th><th>Status</th><th class="num">Accounts</th></tr></thead>
                <tbody>
                    <?php if (empty($membership['accounts'])): ?><tr><td colspan="3" class="text-muted">No accounts yet.</td></tr><?php endif; ?>
                    <?php foreach ($membership['accounts'] as $a): ?>
                        <tr><td><?= esc($a['account_type']) ?></td><td><?= esc($a['account_status']) ?></td><td class="num"><?= (int) $a['n'] ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php elseif ($f['category'] === 'ledger'): ?>
        <section aria-labelledby="h-ledger">
            <h2 id="h-ledger">Ledger integrity</h2>
            <table class="table table-sm" style="max-width:560px;">
                <tbody>
                    <tr><td>Entries recorded in this period</td><td class="num"><?= $ledger['entries'] ?></td></tr>
                    <tr><td>Entries in the whole ledger</td><td class="num"><?= $ledger['total'] ?></td></tr>
                    <tr><td>Entries marked Tampered at the last verification</td><td class="num"><?= $ledger['tampered'] ?></td></tr>
                </tbody>
            </table>
            <p class="small text-muted">Run the verification on the <a href="<?= base_url('admin/ledger') ?>">Blockchain Ledger</a> page to re-check every hash.</p>
        </section>
    <?php endif; ?>
</div>

<script>
// Update the report as soon as a filter changes; picking dates switches to custom dates.
(function () {
    const form = document.getElementById('report-filters');
    const period = document.getElementById('r_period');
    form.querySelectorAll('select, input').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.type === 'date') period.value = 'custom';
            form.submit();
        });
    });
})();
</script>

<?= $this->endSection() ?>
