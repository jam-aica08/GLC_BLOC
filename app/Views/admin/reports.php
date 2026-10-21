<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
$peso     = fn($n) => '₱' . number_format((float) $n, 2);
$base     = ['period' => $f['period'], 'from' => $f['from'], 'to' => $f['to'], 'category' => $f['category'], 'type' => $f['type'], 'status' => $f['status']];
$query    = array_filter($base, fn($v) => $v !== '');
// Report URL with some filters changed; custom dates unless a preset is picked.
$link     = function (array $change) use ($base) {
    $q = array_merge($base, $change);
    if ($q['period'] !== 'custom') {
        unset($q['from'], $q['to']);
    }
    return base_url('admin/reports?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null)));
};
$types    = $f['type'] !== '' ? [$f['type']] : $f['types'];
$statuses = $f['status'] !== '' ? [$f['status']] : ['Approved', 'Pending', 'Rejected'];
$titles   = ['summary' => 'Financial summary', 'transactions' => 'Transaction list', 'membership' => 'Membership and accounts', 'ledger' => 'Ledger integrity'];
$tabIcons = ['summary' => 'fa-chart-column', 'transactions' => 'fa-list', 'membership' => 'fa-users', 'ledger' => 'fa-link'];
$periods  = ['month' => 'This month', '30days' => 'Last 30 days', 'year' => 'This year', 'all' => 'All time', 'custom' => 'Custom'];
// Series colours for the four record types (validated as a categorical set).
$colors   = ['Contribution' => '#3a37c4', 'Loan Disbursement' => '#e0067a', 'Loan Payment' => '#0f9fbf', 'Dividend' => '#eb6834'];
$badges   = ['Approved' => 'bg-success', 'Pending' => 'bg-warning text-dark', 'Rejected' => 'bg-danger'];
$statusIcon = ['Approved' => 'fa-circle-check', 'Pending' => 'fa-clock', 'Rejected' => 'fa-circle-xmark'];
$range    = fn($from, $to) => date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

// Money in/out from approved records only.
$sumOf = function (array $s, array $typeList, string $status = 'Approved'): array {
    $amount = 0.0; $count = 0;
    foreach ($typeList as $t) {
        $amount += $s[$t][$status]['amount'] ?? 0;
        $count  += $s[$t][$status]['count'] ?? 0;
    }
    return ['amount' => $amount, 'count' => $count];
};
?>
<style>
    .report-tabs { gap: 4px; flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; border-bottom: 1px solid var(--glc-border); }
    .report-tabs::-webkit-scrollbar { display: none; }
    .report-tabs .nav-link { color: var(--glc-muted); font-weight: 600; font-size: .9rem; border: 0; border-bottom: 2px solid transparent; border-radius: 0; padding: 10px 14px; white-space: nowrap; }
    .report-tabs .nav-link:hover { color: var(--glc-indigo); }
    .report-tabs .nav-link.active { color: var(--glc-indigo); border-bottom-color: var(--glc-pink); background: none; }
    .period-group { flex-wrap: wrap; gap: 6px; }
    .period-group .btn { border-radius: 999px !important; font-size: .82rem; padding: 5px 12px; }
    .period-group .btn-check:checked + .btn { background: var(--glc-indigo); border-color: var(--glc-indigo); color: #fff; }
    .filter-bar { background: #fff; border: 1px solid var(--glc-border); border-radius: var(--glc-radius); padding: 14px 16px; }
    .filter-bar .form-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--glc-muted); font-weight: 600; margin-bottom: 6px; }

    #report-body { transition: opacity .15s ease; }
    #report-body.is-loading { opacity: .45; pointer-events: none; }

    .kpi { display: block; height: 100%; text-decoration: none; color: inherit; border: 1px solid var(--glc-border); border-radius: var(--glc-radius); background: #fff; padding: 16px 18px; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease; }
    a.kpi:hover, a.kpi:focus-visible { border-color: rgba(var(--glc-indigo-rgb), .45); box-shadow: 0 6px 18px rgba(16,15,60,.08); transform: translateY(-1px); color: inherit; }
    .kpi .kpi-label { font-size: .8rem; color: var(--glc-muted); font-weight: 600; display: flex; align-items: center; gap: 8px; }
    .kpi .kpi-label i { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: rgba(var(--glc-indigo-rgb), .08); color: var(--glc-indigo); font-size: .8rem; }
    .kpi .kpi-value { font-size: 1.5rem; font-weight: 700; margin-top: 8px; line-height: 1.2; color: var(--glc-text); overflow-wrap: anywhere; }
    @media (max-width: 575.98px) {
        .kpi { padding: 12px; }
        .kpi .kpi-value { font-size: 1.1rem; }
        .kpi .kpi-label i { display: none; }
    }
    .kpi .kpi-sub { font-size: .78rem; color: var(--glc-muted); margin-top: 4px; }
    .delta { font-weight: 600; }
    .delta.up { color: #006300; } .delta.down { color: #b4232c; } .delta.flat { color: var(--glc-muted); }

    .chart-card .card-body { position: relative; }
    .chart-box { position: relative; height: 300px; }
    .chart-box.short { height: 220px; }
    .legend { display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: .8rem; color: var(--glc-muted); }
    .legend button { background: none; border: 0; padding: 2px 0; color: inherit; display: inline-flex; align-items: center; gap: 6px; }
    .legend button[aria-pressed="false"] { opacity: .45; text-decoration: line-through; }
    .swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; flex-shrink: 0; }
    .series-name { display: inline-flex; align-items: center; gap: 8px; }

    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cell-link { color: inherit; text-decoration: none; display: inline-block; padding: 2px 6px; margin: -2px -6px; border-radius: 6px; }
    .cell-link:hover, .cell-link:focus-visible { background: rgba(var(--glc-indigo-rgb), .07); color: var(--glc-indigo); }
    .cell-link.zero { color: #6b6f8a; }

    .sort-btn { background: none; border: 0; padding: 0; font: inherit; color: inherit; text-transform: inherit; letter-spacing: inherit; display: inline-flex; gap: 4px; align-items: center; }
    .sort-btn i { opacity: .35; font-size: .7rem; }
    .sort-btn[data-dir] i { opacity: 1; color: var(--glc-indigo); }

    .meter { height: 8px; border-radius: 4px; background: #eceef7; overflow: hidden; display: flex; gap: 2px; }
    .meter span { display: block; height: 100%; }

    @media print {
        .filter-bar, .report-tabs, .no-print { display: none !important; }
        #report-body { opacity: 1 !important; }
        .chart-box { height: 260px; }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h4 class="mb-1 fw-bold">Reports</h4>
        <p class="mb-0 small text-muted" id="report-subtitle"><?= esc($titles[$f['category']]) ?> · <?= esc($range($f['from'], $f['to'])) ?> · generated <?= date('M j, Y g:i A') ?></p>
    </div>
    <div class="d-flex gap-2 no-print">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
        <a class="btn btn-sm btn-outline-secondary" id="report-csv" href="<?= base_url('admin/reports/export?' . http_build_query($query)) ?>"><i class="fa-solid fa-file-csv me-1"></i>Download CSV</a>
    </div>
</div>

<!-- Filters: one row that scopes everything below -->
<form method="get" action="<?= base_url('admin/reports') ?>" class="filter-bar mb-3" id="report-filters" aria-label="Report filters">
    <input type="hidden" name="category" value="<?= esc($f['category']) ?>">
    <div class="row g-3 align-items-end">
        <div class="col-lg">
            <span class="form-label d-block" id="period-label">Reporting period</span>
            <div class="d-flex period-group" role="radiogroup" aria-labelledby="period-label">
                <?php foreach ($periods as $k => $v): ?>
                    <input type="radio" class="btn-check" name="period" id="p_<?= $k ?>" value="<?= $k ?>" <?= $f['period'] === $k ? 'checked' : '' ?>>
                    <label class="btn btn-outline-secondary" for="p_<?= $k ?>"><?= $v ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="col-6 col-md-3 col-lg-auto js-custom" <?= $f['period'] === 'custom' ? '' : 'hidden' ?>>
            <label for="r_from" class="form-label">From</label>
            <input type="date" id="r_from" name="from" class="form-control form-control-sm" value="<?= esc($f['from']) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-6 col-md-3 col-lg-auto js-custom" <?= $f['period'] === 'custom' ? '' : 'hidden' ?>>
            <label for="r_to" class="form-label">To</label>
            <input type="date" id="r_to" name="to" class="form-control form-control-sm" value="<?= esc($f['to']) ?>">
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label for="r_type" class="form-label">Type</label>
            <select id="r_type" name="type" class="form-select form-select-sm">
                <option value="">All types</option>
                <?php foreach ($f['types'] as $t): ?><option value="<?= esc($t) ?>" <?= $f['type'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label for="r_status" class="form-label">Status</label>
            <select id="r_status" name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                <?php foreach (['Approved', 'Pending', 'Rejected'] as $st): ?><option value="<?= $st ?>" <?= $f['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <noscript><button class="btn btn-sm btn-brand mt-3">Update report</button></noscript>
</form>

<div id="report-body" aria-live="polite">
    <ul class="nav report-tabs mb-4" role="tablist">
        <?php foreach ($titles as $k => $v): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link<?= $f['category'] === $k ? ' active' : '' ?>" href="<?= $link(['category' => $k]) ?>" data-report-link role="tab"
                   aria-selected="<?= $f['category'] === $k ? 'true' : 'false' ?>"><i class="fa-solid <?= $tabIcons[$k] ?> me-2"></i><?= $v ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($f['category'] === 'summary'):
        $inTypes  = array_values(array_intersect($types, ['Contribution', 'Loan Payment']));
        $outTypes = array_values(array_intersect($types, ['Loan Disbursement', 'Dividend']));
        $kpis = [
            'in'      => $sumOf($summary, $inTypes),
            'out'     => $sumOf($summary, $outTypes),
            'pending' => $sumOf($summary, $types, 'Pending'),
        ];
        $prev = $previous ? [
            'in'  => $sumOf($previous['summary'], $inTypes),
            'out' => $sumOf($previous['summary'], $outTypes),
        ] : null;
        $net     = $kpis['in']['amount'] - $kpis['out']['amount'];
        $prevNet = $prev ? $prev['in']['amount'] - $prev['out']['amount'] : null;
        // Signed change vs the previous period; $goodUp says whether a rise is good news.
        $delta = function (float $now, ?float $before, ?bool $goodUp) use ($previous, $range) {
            if ($before === null) return '';
            $when = 'vs ' . $range($previous['from'], $previous['to']);
            if (abs($now - $before) < 0.005) return '<span class="delta flat">No change</span> ' . esc($when);
            $pct  = $before != 0.0 ? round(($now - $before) / abs($before) * 100) : null;
            $up   = $now > $before;
            $cls  = $goodUp === null ? 'flat' : (($up === $goodUp) ? 'up' : 'down');
            $txt  = ($up ? '▲ ' : '▼ ') . ($pct !== null ? abs($pct) . '%' : 'new');
            return '<span class="delta ' . $cls . '">' . $txt . '</span> ' . esc($when);
        };
    ?>
        <!-- Headline numbers -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <a class="kpi" href="<?= $link(['category' => 'transactions', 'status' => 'Approved']) ?>" data-report-link>
                    <div class="kpi-label"><i class="fa-solid fa-arrow-down-long"></i>Money in</div>
                    <div class="kpi-value"><?= $peso($kpis['in']['amount']) ?></div>
                    <div class="kpi-sub"><?= $kpis['in']['count'] ?> approved record(s)</div>
                    <div class="kpi-sub"><?= $delta($kpis['in']['amount'], $prev['in']['amount'] ?? null, true) ?></div>
                </a>
            </div>
            <div class="col-6 col-xl-3">
                <a class="kpi" href="<?= $link(['category' => 'transactions', 'status' => 'Approved']) ?>" data-report-link>
                    <div class="kpi-label"><i class="fa-solid fa-arrow-up-long"></i>Money out</div>
                    <div class="kpi-value"><?= $peso($kpis['out']['amount']) ?></div>
                    <div class="kpi-sub"><?= $kpis['out']['count'] ?> approved record(s)</div>
                    <div class="kpi-sub"><?= $delta($kpis['out']['amount'], $prev['out']['amount'] ?? null, null) ?></div>
                </a>
            </div>
            <div class="col-6 col-xl-3">
                <div class="kpi">
                    <div class="kpi-label"><i class="fa-solid fa-scale-balanced"></i>Net (in − out)</div>
                    <div class="kpi-value"><?= ($net < 0 ? '−' : '') . $peso(abs($net)) ?></div>
                    <div class="kpi-sub">Approved records only</div>
                    <div class="kpi-sub"><?= $delta($net, $prevNet, true) ?></div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <a class="kpi" href="<?= $link(['category' => 'transactions', 'status' => 'Pending']) ?>" data-report-link>
                    <div class="kpi-label"><i class="fa-solid fa-clock"></i>Waiting for approval</div>
                    <div class="kpi-value"><?= $kpis['pending']['count'] ?> <span class="fs-6 fw-semibold text-muted">record(s)</span></div>
                    <div class="kpi-sub"><?= $peso($kpis['pending']['amount']) ?> pending</div>
                    <div class="kpi-sub"><span class="text-brand fw-semibold">Review list <i class="fa-solid fa-arrow-right small"></i></span></div>
                </a>
            </div>
        </div>

        <?php
        $trendSeries = array_map(fn($t) => ['type' => $t, 'color' => $colors[$t], 'values' => array_map(fn($b) => $b['values'][$t], $trend['buckets'])], $types);
        $byType      = array_map(fn($t) => ['type' => $t, 'color' => $colors[$t], 'amount' => $summary[$t][$trend['status']]['amount'] ?? 0, 'count' => $summary[$t][$trend['status']]['count'] ?? 0], $types);
        $chartData   = [
            'status'  => $trend['status'],
            'unit'    => $trend['unit'],
            'labels'  => array_column($trend['buckets'], 'label'),
            'links'   => array_map(fn($b) => $link(['category' => 'transactions', 'period' => 'custom', 'from' => $b['from'], 'to' => $b['to'], 'status' => $trend['status']]), $trend['buckets']),
            'series'  => $trendSeries,
            'byType'  => $byType,
            'byTypeLinks' => array_map(fn($t) => $link(['category' => 'transactions', 'type' => $t, 'status' => $trend['status']]), $types),
        ];
        $hasTrend = array_sum(array_map(fn($s) => array_sum($s['values']), $trendSeries)) > 0;
        ?>
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card chart-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-chart-column me-2 glc-icon"></i><?= esc($trend['status']) ?> amounts per <?= esc($trend['unit']) ?></span>
                        <span class="small text-muted fw-normal no-print">Click a column to see its transactions</span>
                    </div>
                    <div class="card-body">
                        <div class="legend mb-2 js-legend" aria-label="Series"></div>
                        <?php if ($hasTrend): ?>
                            <div class="chart-box"><canvas id="trendChart" role="img" aria-label="<?= esc($trend['status']) ?> amounts per <?= esc($trend['unit']) ?> by record type. The same numbers are in the table below the chart."></canvas></div>
                        <?php else: ?>
                            <div class="chart-box d-flex align-items-center justify-content-center text-muted small text-center">
                                <div><i class="fa-regular fa-chart-bar fa-2x mb-2 d-block opacity-50"></i>No <?= esc(strtolower($trend['status'])) ?> transactions in this period.</div>
                            </div>
                        <?php endif; ?>
                        <details class="mt-3 no-print">
                            <summary class="small text-brand fw-semibold" style="cursor:pointer;">Show the numbers as a table</summary>
                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th><?= ucfirst(esc($trend['unit'])) ?></th><?php foreach ($types as $t): ?><th class="num"><?= esc($t) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($trend['buckets'] as $i => $b): $rowTotal = array_sum(array_intersect_key($b['values'], array_flip($types))); ?>
                                            <tr>
                                                <td><a class="cell-link" href="<?= $chartData['links'][$i] ?>" data-report-link><?= esc($b['label']) ?></a></td>
                                                <?php foreach ($types as $t): ?><td class="num"><?= $peso($b['values'][$t]) ?></td><?php endforeach; ?>
                                                <td class="num fw-semibold"><?= $peso($rowTotal) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card chart-card h-100">
                    <div class="card-header"><i class="fa-solid fa-layer-group me-2 glc-icon"></i><?= esc($trend['status']) ?> amount by type</div>
                    <div class="card-body">
                        <div class="chart-box short"><canvas id="typeChart" role="img" aria-label="<?= esc($trend['status']) ?> amount by record type. The same numbers are in the totals table below."></canvas></div>
                        <p class="small text-muted mb-0 mt-2 no-print">Click a bar to list that type.</p>
                    </div>
                </div>
            </div>
        </div>
        <script type="application/json" id="report-data"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>

    <?php // Totals by type and status, for every category: first on the summary and list,
    // after the tab's own numbers on membership and ledger.
    ob_start(); ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-table me-2 glc-icon"></i>Totals by transaction type</span>
            <span class="small text-muted fw-normal no-print">Click an amount to list those records</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr><th>Type</th><?php foreach ($statuses as $st): ?><th class="num"><?= $st ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php $colTotals = []; ?>
                    <?php foreach ($types as $t): ?>
                        <tr>
                            <td><span class="series-name"><span class="swatch" style="background:<?= $colors[$t] ?>"></span><?= esc($t) ?></span></td>
                            <?php foreach ($statuses as $st): $cell = $summary[$t][$st] ?? ['count' => 0, 'amount' => 0];
                                $colTotals[$st]['count']  = ($colTotals[$st]['count'] ?? 0) + $cell['count'];
                                $colTotals[$st]['amount'] = ($colTotals[$st]['amount'] ?? 0) + $cell['amount']; ?>
                                <td class="num">
                                    <a class="cell-link<?= $cell['count'] ? '' : ' zero' ?>" href="<?= $link(['category' => 'transactions', 'type' => $t, 'status' => $st]) ?>" data-report-link
                                       aria-label="<?= esc($st . ' ' . $t . ': ' . $peso($cell['amount']) . ', ' . $cell['count'] . ' record(s)') ?>">
                                        <?= $peso($cell['amount']) ?> <span class="text-muted small">(<?= $cell['count'] ?>)</span>
                                    </a>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-semibold"><td>Total</td><?php foreach ($statuses as $st): ?><td class="num"><?= $peso($colTotals[$st]['amount'] ?? 0) ?> <span class="text-muted small">(<?= $colTotals[$st]['count'] ?? 0 ?>)</span></td><?php endforeach; ?></tr>
                </tfoot>
            </table>
        </div>
        <div class="card-body py-2 small text-muted border-top">Amounts in pesos, number of records in brackets. Dates use each record's transaction date.</div>
    </div>

    <?php $totalsCard = ob_get_clean(); ?>
    <?php if (in_array($f['category'], ['summary', 'transactions'], true)) echo $totalsCard; ?>

    <?php if ($f['category'] === 'transactions'): ?>
        <div class="card" id="tx-card">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <span><i class="fa-solid fa-list me-2 glc-icon"></i>Transactions</span>
                <?php if (! empty($records)): ?>
                    <div class="d-flex flex-wrap gap-2 align-items-center no-print">
                        <input type="search" class="form-control form-control-sm js-tx-search" placeholder="Search member, account, record #…" aria-label="Search transactions" style="width:min(260px,100%);">
                        <select class="form-select form-select-sm js-tx-sort d-md-none" aria-label="Sort transactions" style="width:auto;">
                            <option value="date:desc">Newest first</option>
                            <option value="date:asc">Oldest first</option>
                            <option value="amount:desc">Largest amount</option>
                            <option value="amount:asc">Smallest amount</option>
                            <option value="member:asc">Member A–Z</option>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (empty($records)): ?>
                <div class="card-body text-center text-muted py-5">
                    <i class="fa-regular fa-folder-open fa-2x mb-2 d-block opacity-50"></i>No transactions match these filters.
                </div>
            <?php else: ?>
                <div class="px-3 py-2 small text-muted border-bottom d-flex flex-wrap justify-content-between gap-2" aria-live="polite">
                    <span class="js-tx-count"></span>
                    <span>Total shown: <strong class="text-body js-tx-total"></strong></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 js-tx-table">
                        <thead>
                            <tr>
                                <th><button type="button" class="sort-btn" data-sort="date">Date <i class="fa-solid fa-sort"></i></button></th>
                                <th>Record</th>
                                <th><button type="button" class="sort-btn" data-sort="type">Type <i class="fa-solid fa-sort"></i></button></th>
                                <th><button type="button" class="sort-btn" data-sort="member">Member <i class="fa-solid fa-sort"></i></button></th>
                                <th>Account</th>
                                <th><button type="button" class="sort-btn" data-sort="status">Status <i class="fa-solid fa-sort"></i></button></th>
                                <th class="num"><button type="button" class="sort-btn" data-sort="amount">Amount <i class="fa-solid fa-sort"></i></button></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr data-date="<?= esc($r['transaction_date']) ?>" data-id="<?= (int) $r['financial_record_id'] ?>" data-amount="<?= (float) $r['amount'] ?>"
                                    data-type="<?= esc($r['record_type']) ?>" data-member="<?= esc(strtolower($r['member_name'] ?? '')) ?>" data-status="<?= esc($r['approval_status']) ?>">
                                    <td class="text-nowrap"><?= esc(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
                                    <td>#<?= (int) $r['financial_record_id'] ?></td>
                                    <td><span class="series-name"><span class="swatch" style="background:<?= $colors[$r['record_type']] ?? '#999' ?>"></span><?= esc($r['record_type']) ?></span></td>
                                    <td><?= esc($r['member_name'] ?? '—') ?> <span class="text-muted small"><?= esc($r['member_number'] ?? '') ?></span></td>
                                    <td><?= esc($r['account_number'] ?? '—') ?></td>
                                    <td><span class="badge <?= $badges[$r['approval_status']] ?? 'bg-secondary' ?>"><i class="fa-solid <?= $statusIcon[$r['approval_status']] ?? 'fa-circle' ?> me-1"></i><?= esc($r['approval_status']) ?></span></td>
                                    <td class="num fw-semibold"><?= $peso($r['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-body py-2 d-flex flex-wrap justify-content-between align-items-center gap-2 border-top no-print">
                    <span class="small text-muted js-tx-page"></span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary js-tx-prev"><i class="fa-solid fa-chevron-left me-1"></i>Previous</button>
                        <button type="button" class="btn btn-outline-secondary js-tx-next">Next<i class="fa-solid fa-chevron-right ms-1"></i></button>
                    </div>
                </div>
                <?php if (count($records) === 500): ?><div class="card-body pt-0 small text-muted">Showing the latest 500. Download the CSV for every row.</div><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php elseif ($f['category'] === 'membership'):
        $m = $membership;
        $acctStatuses = ['Active' => ['#0ca30c', 'fa-circle-check'], 'Pending' => ['#fab219', 'fa-clock'], 'Inactive' => ['#9a9db5', 'fa-circle-pause'], 'Closed' => ['#d03b3b', 'fa-circle-xmark']];
        $byAcct = [];
        foreach ($m['accounts'] as $a) { $byAcct[$a['account_type']][$a['account_status']] = (int) $a['n']; }
    ?>
        <div class="row g-3 mb-4">
            <?php foreach ([
                ['Members', 'fa-users', $m['members'], 'All members on record'],
                ['Active members', 'fa-user-check', $m['active_members'], ($m['members'] - $m['active_members']) . ' inactive or suspended'],
                ['Joined in this period', 'fa-user-plus', $m['new_members'], esc($range($f['from'], $f['to']))],
                ['Accounts opened', 'fa-wallet', $m['opened'], 'In this period'],
            ] as [$label, $icon, $value, $sub]): ?>
                <div class="col-6 col-xl-3">
                    <div class="kpi">
                        <div class="kpi-label"><i class="fa-solid <?= $icon ?>"></i><?= $label ?></div>
                        <div class="kpi-value"><?= (int) $value ?></div>
                        <div class="kpi-sub"><?= $sub ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fa-solid fa-wallet me-2 glc-icon"></i>Accounts by type and status</span>
                <span class="d-flex flex-wrap gap-3 small fw-normal text-muted">
                    <?php foreach ($acctStatuses as $st => [$c, $ic]): ?><span><i class="fa-solid <?= $ic ?> me-1" style="color:<?= $c ?>"></i><?= $st ?></span><?php endforeach; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($byAcct)): ?>
                    <p class="text-muted mb-0">No accounts yet.</p>
                <?php endif; ?>
                <?php foreach ($byAcct as $type => $counts): $total = array_sum($counts); ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1"><strong><?= esc($type) ?></strong><span class="text-muted"><?= $total ?> account(s)</span></div>
                        <div class="meter" role="img" aria-label="<?= esc($type . ': ' . implode(', ', array_map(fn($s, $n) => "$n $s", array_keys($counts), $counts))) ?>">
                            <?php foreach ($acctStatuses as $st => [$c]): if (empty($counts[$st])) continue; ?>
                                <span style="width:<?= round($counts[$st] / $total * 100, 2) ?>%;background:<?= $c ?>" title="<?= $counts[$st] . ' ' . $st ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-3 small text-muted mt-1">
                            <?php foreach ($acctStatuses as $st => [$c, $ic]): if (empty($counts[$st])) continue; ?>
                                <span><i class="fa-solid <?= $ic ?> me-1" style="color:<?= $c ?>"></i><?= $counts[$st] ?> <?= $st ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php elseif ($f['category'] === 'ledger'):
        $lg = $ledger;
        $healthy = $lg['tampered'] === 0;
    ?>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="kpi">
                    <div class="kpi-label"><i class="fa-solid fa-cubes"></i>Entries in this period</div>
                    <div class="kpi-value"><?= (int) $lg['entries'] ?></div>
                    <div class="kpi-sub"><?= esc($range($f['from'], $f['to'])) ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="kpi">
                    <div class="kpi-label"><i class="fa-solid fa-link"></i>Entries in the whole ledger</div>
                    <div class="kpi-value"><?= (int) $lg['total'] ?></div>
                    <div class="kpi-sub">Every chained record</div>
                </div>
            </div>
            <div class="col-md-4">
                <a class="kpi" href="<?= base_url('admin/ledger') ?>">
                    <div class="kpi-label"><i class="fa-solid fa-shield-halved"></i>Integrity at last verification</div>
                    <div class="kpi-value" style="color:<?= $healthy ? '#006300' : '#b4232c' ?>">
                        <i class="fa-solid <?= $healthy ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-1"></i><?= $healthy ? 'Healthy' : $lg['tampered'] . ' tampered' ?>
                    </div>
                    <div class="kpi-sub"><span class="text-brand fw-semibold">Run verification <i class="fa-solid fa-arrow-right small"></i></span></div>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (in_array($f['category'], ['membership', 'ledger'], true)): ?><div class="mt-4"><?= $totalsCard ?></div><?php endif; ?>
</div>

<script>
// Interactive reports: filters and tabs reload only the report body, charts
// drill down into transactions, and the transaction list sorts, searches and pages.
(function () {
    const form = document.getElementById('report-filters');
    const body = document.getElementById('report-body');
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const compact = n => '₱' + Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
    let charts = [];
    let pending;

    function load(url, push) {
        if (pending) pending.abort();
        pending = new AbortController();
        body.classList.add('is-loading');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: pending.signal })
            .then(r => { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(html => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const next = doc.getElementById('report-body');
                if (!next) { location.href = url; return; }
                ['report-subtitle', 'report-csv'].forEach(id => {
                    const a = document.getElementById(id), b = doc.getElementById(id);
                    if (a && b) { a.innerHTML = b.innerHTML; if (b.href) a.href = b.href; }
                });
                // Keep the filter bar in step with the URL (tabs and links change it too).
                const nextForm = doc.getElementById('report-filters');
                form.querySelectorAll('[name]').forEach(el => {
                    const src = nextForm.querySelector('[name="' + el.name + '"]' + (el.type === 'radio' ? '[value="' + el.value + '"]' : ''));
                    if (!src) return;
                    if (el.type === 'radio') el.checked = src.checked; else el.value = src.value;
                });
                syncCustom();
                charts.forEach(c => c.destroy()); charts = [];
                body.innerHTML = next.innerHTML;
                if (push) history.pushState(null, '', url);
                init();
            })
            .catch(e => { if (e.name !== 'AbortError') location.href = url; })
            .finally(() => body.classList.remove('is-loading'));
    }

    function formUrl() {
        const q = new URLSearchParams(new FormData(form));
        if (q.get('period') !== 'custom') { q.delete('from'); q.delete('to'); }
        [...q.keys()].forEach(k => { if (q.get(k) === '') q.delete(k); });
        return form.action + '?' + q.toString();
    }

    function syncCustom() {
        const custom = form.querySelector('[name="period"]:checked').value === 'custom';
        form.querySelectorAll('.js-custom').forEach(el => { el.hidden = !custom; });
    }

    form.addEventListener('change', function (e) {
        syncCustom();
        const from = form.elements.from, to = form.elements.to;
        if (e.target.type === 'date' && from.value && to.value && from.value > to.value) {
            (e.target === from ? to : from).value = e.target.value;
        }
        if (e.target.name === 'period' && e.target.value === 'custom') { from.focus(); return; }
        load(formUrl(), true);
    });
    form.addEventListener('submit', e => { e.preventDefault(); load(formUrl(), true); });

    body.addEventListener('click', function (e) {
        const a = e.target.closest('a[data-report-link]');
        if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        load(a.href, true);
        if (a.closest('.report-tabs') === null) window.scrollTo({ top: body.offsetTop - 80, behavior: 'smooth' });
    });
    window.addEventListener('popstate', () => load(location.href, false));

    function init() {
        if (window.glcStackTables) window.glcStackTables(body);
        const dataEl = document.getElementById('report-data');
        if (dataEl && window.Chart) charts = drawCharts(JSON.parse(dataEl.textContent));
        const tx = body.querySelector('.js-tx-table');
        if (tx) transactions(tx);
    }

    // ---------------------------------------------------------------- charts
    function drawCharts(d) {
        const out = [];
        const grid = '#eceef5', ink = '#61657f';
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = ink;

        const legend = body.querySelector('.js-legend');
        const trendEl = document.getElementById('trendChart');
        if (trendEl) {
            const trend = new Chart(trendEl, {
                type: 'bar',
                data: {
                    labels: d.labels,
                    datasets: d.series.map(s => ({
                        label: s.type, data: s.values, backgroundColor: s.color, hoverBackgroundColor: s.color + 'cc',
                        borderColor: '#fff', borderWidth: { top: 2 }, borderSkipped: 'start', borderRadius: 4, maxBarThickness: 24,
                    })),
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { stacked: true, grid: { display: false }, border: { color: '#d7d9e6' }, ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 12 } },
                        y: { stacked: true, beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { callback: v => compact(v), maxTicksLimit: 6 } },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#fff', titleColor: '#16152b', bodyColor: '#16152b', footerColor: '#16152b',
                            borderColor: '#e5e7f2', borderWidth: 1, padding: 10, boxPadding: 6, usePointStyle: true,
                            filter: item => item.raw > 0,
                            callbacks: {
                                label: c => ' ' + peso(c.raw) + '  ' + c.dataset.label,
                                labelPointStyle: () => ({ pointStyle: 'line', rotation: 0 }),
                                footer: items => items.length > 1 ? 'Total ' + peso(items.reduce((a, i) => a + i.raw, 0)) : '',
                            },
                        },
                    },
                    onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                    onClick: (e, els) => { if (els.length) load(d.links[els[0].index], true); },
                },
            });
            out.push(trend);

            // Legend that doubles as a series toggle (identity never by colour alone).
            if (legend && d.series.length > 1) {
                d.series.forEach((s, i) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.setAttribute('aria-pressed', 'true');
                    const sw = document.createElement('span'); sw.className = 'swatch'; sw.style.background = s.color;
                    b.append(sw, document.createTextNode(s.type));
                    b.addEventListener('click', () => {
                        const on = trend.isDatasetVisible(i);
                        trend.setDatasetVisibility(i, !on);
                        b.setAttribute('aria-pressed', on ? 'false' : 'true');
                        trend.update();
                    });
                    legend.appendChild(b);
                });
            }
        }

        const typeEl = document.getElementById('typeChart');
        if (typeEl) {
            // Value at the end of each bar, in text ink.
            const endLabels = {
                id: 'endLabels',
                afterDatasetsDraw(chart) {
                    const { ctx } = chart;
                    ctx.save();
                    ctx.font = '600 12px ' + Chart.defaults.font.family;
                    ctx.fillStyle = '#16152b';
                    ctx.textBaseline = 'middle';
                    chart.getDatasetMeta(0).data.forEach((bar, i) => {
                        const text = compact(d.byType[i].amount);
                        const w = ctx.measureText(text).width;
                        const fits = bar.x + 6 + w < chart.chartArea.right + 40;
                        ctx.textAlign = fits ? 'left' : 'right';
                        ctx.fillStyle = fits ? '#16152b' : '#fff';
                        ctx.fillText(text, fits ? bar.x + 6 : bar.x - 6, bar.y);
                    });
                    ctx.restore();
                },
            };
            const max = Math.max(1, ...d.byType.map(t => t.amount));
            out.push(new Chart(typeEl, {
                type: 'bar',
                data: {
                    labels: d.byType.map(t => t.type),
                    datasets: [{
                        data: d.byType.map(t => t.amount), backgroundColor: d.byType.map(t => t.color),
                        hoverBackgroundColor: d.byType.map(t => t.color + 'cc'), borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22,
                    }],
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    layout: { padding: { right: 44 } },
                    scales: {
                        x: { beginAtZero: true, suggestedMax: max * 1.05, grid: { color: grid }, border: { display: false }, ticks: { callback: v => compact(v), maxTicksLimit: 4 } },
                        y: { grid: { display: false }, border: { color: '#d7d9e6' }, ticks: { color: '#16152b' } },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#fff', titleColor: '#16152b', bodyColor: '#16152b', borderColor: '#e5e7f2', borderWidth: 1, padding: 10,
                            displayColors: false,
                            callbacks: { label: c => peso(c.raw) + ' · ' + d.byType[c.dataIndex].count + ' record(s)' },
                        },
                    },
                    onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                    onClick: (e, els) => { if (els.length) load(d.byTypeLinks[els[0].index], true); },
                },
                plugins: [endLabels],
            }));
        }
        return out;
    }

    // ---------------------------------------------------------- transactions
    function transactions(table) {
        const card = table.closest('.card');
        const rows = [...table.tBodies[0].rows];
        const search = card.querySelector('.js-tx-search');
        const sortSel = card.querySelector('.js-tx-sort');
        const perPage = 25;
        let key = 'date', dir = 'desc', page = 0, shown = rows;

        const val = (r, k) => k === 'amount' ? +r.dataset.amount : k === 'date' ? r.dataset.date + String(r.dataset.id).padStart(10, '0') : (r.dataset[k] || '');

        function render() {
            const q = (search.value || '').trim().toLowerCase();
            shown = rows.filter(r => !q || r.textContent.toLowerCase().includes(q));
            shown.sort((a, b) => { const x = val(a, key), y = val(b, key); return (x > y ? 1 : x < y ? -1 : 0) * (dir === 'asc' ? 1 : -1); });
            const pages = Math.max(1, Math.ceil(shown.length / perPage));
            page = Math.min(page, pages - 1);
            const tbody = table.tBodies[0];
            rows.forEach(r => { r.hidden = true; });
            shown.forEach((r, i) => { tbody.appendChild(r); r.hidden = i < page * perPage || i >= (page + 1) * perPage; });
            card.querySelector('.js-tx-count').textContent = shown.length + ' of ' + rows.length + ' transaction(s)' + (q ? ' match “' + search.value.trim() + '”' : '');
            card.querySelector('.js-tx-total').textContent = peso(shown.reduce((a, r) => a + +r.dataset.amount, 0));
            card.querySelector('.js-tx-page').textContent = shown.length ? 'Page ' + (page + 1) + ' of ' + pages : 'No matching transactions';
            card.querySelector('.js-tx-prev').disabled = page === 0;
            card.querySelector('.js-tx-next').disabled = page >= pages - 1;
            table.querySelectorAll('.sort-btn').forEach(b => {
                const on = b.dataset.sort === key;
                b.toggleAttribute('data-dir', on);
                b.querySelector('i').className = 'fa-solid ' + (on ? (dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort');
                b.closest('th').setAttribute('aria-sort', on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
            });
            if (sortSel) sortSel.value = key + ':' + dir;
        }

        table.querySelectorAll('.sort-btn').forEach(b => b.addEventListener('click', () => {
            if (key === b.dataset.sort) dir = dir === 'asc' ? 'desc' : 'asc';
            else { key = b.dataset.sort; dir = key === 'date' || key === 'amount' ? 'desc' : 'asc'; }
            page = 0; render();
        }));
        if (sortSel) sortSel.addEventListener('change', () => { [key, dir] = sortSel.value.split(':'); page = 0; render(); });
        search.addEventListener('input', () => { page = 0; render(); });
        card.querySelector('.js-tx-prev').addEventListener('click', () => { page--; render(); card.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
        card.querySelector('.js-tx-next').addEventListener('click', () => { page++; render(); card.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
        render();
    }

    syncCustom();
    init();
})();
</script>

<?= $this->endSection() ?>
