<?= $this->extend($layout ?? 'layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Analytics</h4>
        <p class="text-muted mb-0 small">Recent cooperative activity, plus the 12-month equity trend and forecast.</p>
    </div>
    <span class="badge" style="background:var(--glc-gradient-dark);color:#fff;padding:8px 14px;border-radius:8px;font-size:0.8rem;">
        <i class="fa-solid fa-chart-line me-1"></i> Equity Trend &amp; Forecast
    </span>
</div>

<!-- Flash Messages -->
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Recent activity: time range + auto-refresh -->
<div class="card mb-4" id="activity">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
        <span class="fw-semibold"><i class="fa-solid fa-wave-square me-2 glc-icon"></i>Activity</span>
        <div class="d-flex flex-wrap gap-3 align-items-end">
            <div>
                <label for="act_range" class="form-label small fw-semibold mb-1">Time range</label>
                <select id="act_range" class="form-select form-select-sm">
                <option value="30s">30 seconds</option>
                <option value="1m">1 minute</option>
                <option value="2m">2 minutes</option>
                <option value="5m">5 minutes</option>
                <option value="10m">10 minutes</option>
                <option value="30m">30 minutes</option>
                <option value="1h">1 hour</option>
                <option value="5h">5 hours</option>
                <option value="12h">12 hours</option>
                <option value="24h" selected>24 hours</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                </select>
            </div>
            <div>
                <label for="act_refresh" class="form-label small fw-semibold mb-1">Auto-refresh</label>
                <select id="act_refresh" class="form-select form-select-sm">
                    <option value="auto">Match range</option>
                    <option value="0">Off</option>
                    <option value="10">Every 10 seconds</option>
                    <option value="30">Every 30 seconds</option>
                    <option value="60">Every minute</option>
                    <option value="300">Every 5 minutes</option>
                    <option value="900">Every 15 minutes</option>
                </select>
            </div>
            <button type="button" id="act_reload" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-rotate me-1"></i>Refresh now</button>
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 small text-muted mb-3">
            <span id="act_window" aria-live="polite">Loading…</span>
            <span id="act_status"></span>
        </div>
        <div class="row g-3 mb-3" id="act_totals">
            <div class="col-6 col-lg-3"><div class="border rounded p-2 h-100"><div class="small text-muted"><span class="act-key" style="background:#2a78d6"></span>Contributions approved</div><div class="fw-bold" data-total="contributions">—</div></div></div>
            <div class="col-6 col-lg-3"><div class="border rounded p-2 h-100"><div class="small text-muted"><span class="act-key" style="background:#eb6834"></span>Loan payments approved</div><div class="fw-bold" data-total="payments">—</div></div></div>
            <div class="col-6 col-lg-3"><div class="border rounded p-2 h-100"><div class="small text-muted"><span class="act-key" style="background:#1baf7a"></span>Loans released</div><div class="fw-bold" data-total="loans">—</div></div></div>
            <div class="col-6 col-lg-3"><div class="border rounded p-2 h-100"><div class="small text-muted"><span class="act-key" style="background:#eda100"></span>Dividends distributed</div><div class="fw-bold" data-total="dividends">—</div></div></div>
        </div>
        <div class="position-relative" style="height:260px;">
            <canvas id="activityChart" role="img" aria-label="Approved amounts per period for the selected time range"></canvas>
            <div id="act_overlay" class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center text-muted small" style="background:rgba(255,255,255,.75);">Loading activity…</div>
        </div>
        <details class="small mt-2">
            <summary class="text-muted">Show as table</summary>
            <div class="table-responsive"><table class="table table-sm mb-0 mt-2" id="act_table"><thead><tr><th>Period starting</th><th class="text-end">Contributions</th><th class="text-end">Loan payments</th><th class="text-end">Loans released</th><th class="text-end">Dividends</th></tr></thead><tbody></tbody></table></div>
        </details>
    </div>
</div>
<style>.act-key{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:6px;vertical-align:middle;}</style>
<script>
(function () {
    const URL_ = '<?= base_url($activityUrl ?? 'admin/analytics/activity') ?>';
    const COLORS = { contributions: '#2a78d6', payments: '#eb6834', loans: '#1baf7a', dividends: '#eda100' };
    const NAMES  = { contributions: 'Contributions approved', payments: 'Loan payments approved', loans: 'Loans released', dividends: 'Dividends distributed' };
    const range = document.getElementById('act_range'), refreshSel = document.getElementById('act_refresh');
    const windowEl = document.getElementById('act_window'), statusEl = document.getElementById('act_status');
    const overlay = document.getElementById('act_overlay'), tbody = document.querySelector('#act_table tbody');
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let chart = null, timer = null, inFlight = null, defaultRefresh = 300;

    try { const saved = localStorage.getItem('glc-activity-range'); if (saved && range.querySelector('option[value="' + saved + '"]')) range.value = saved; } catch (e) {}

    function setOverlay(text) { overlay.textContent = text || ''; overlay.classList.toggle('d-none', !text); }

    function render(data) {
        defaultRefresh = data.default_refresh;
        windowEl.textContent = data.label + ': ' + data.from + ' to ' + data.to;
        Object.keys(NAMES).forEach(k => {
            const t = data.totals[k];
            document.querySelector('[data-total="' + k + '"]').textContent = peso(t.amount) + ' (' + t.count + ')';
        });
        const datasets = Object.keys(NAMES).map(k => ({
            label: NAMES[k], data: data.series[k], backgroundColor: COLORS[k],
            borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'start', maxBarThickness: 18
        }));
        if (typeof Chart !== 'undefined') {
            if (!chart) {
                chart = new Chart(document.getElementById('activityChart'), {
                    type: 'bar',
                    data: { labels: data.labels, datasets },
                    options: {
                        maintainAspectRatio: false, animation: false,
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } },
                                   tooltip: { callbacks: { label: c => c.dataset.label + ': ' + peso(c.parsed.y) } } },
                        scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                                  y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.06)' }, ticks: { callback: v => '₱' + Number(v).toLocaleString('en-PH') } } }
                    }
                });
            } else {
                chart.data.labels = data.labels;
                datasets.forEach((d, i) => { chart.data.datasets[i].data = d.data; });
                chart.update();
            }
        }
        tbody.innerHTML = data.labels.map((l, i) => '<tr><td>' + l + '</td>' + Object.keys(NAMES).map(k => '<td class="text-end">' + peso(data.series[k][i]) + '</td>').join('') + '</tr>').join('');
        setOverlay(data.empty ? 'No approved activity in this period. ' + data.waiting + ' record(s) are waiting for approval.' : '');
    }

    async function load() {
        if (inFlight) return;                       // never overlap requests
        if (document.hidden) return;                // no calls while the tab is hidden
        inFlight = new AbortController();
        statusEl.textContent = 'Updating…';
        if (!chart) setOverlay('Loading activity…');
        try {
            const res = await fetch(URL_ + '?range=' + encodeURIComponent(range.value), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: inFlight.signal
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            render(data);
            statusEl.textContent = 'Updated ' + data.generated_at;
        } catch (err) {
            if (err.name !== 'AbortError') {
                statusEl.textContent = 'Could not update. Retrying at the next refresh.';
                if (!chart) setOverlay('Could not load activity. Use Refresh now to try again.');
            }
        } finally {
            inFlight = null;
            schedule();
        }
    }

    function interval() {
        const v = refreshSel.value;
        return v === 'auto' ? defaultRefresh : parseInt(v, 10);
    }

    function schedule() {
        clearTimeout(timer);
        const seconds = interval();
        if (seconds > 0) timer = setTimeout(load, seconds * 1000);
    }

    function restart() {
        if (inFlight) inFlight.abort();
        inFlight = null;
        clearTimeout(timer);
        load();
    }

    range.addEventListener('change', () => { try { localStorage.setItem('glc-activity-range', range.value); } catch (e) {} restart(); });
    refreshSel.addEventListener('change', schedule);
    document.getElementById('act_reload').addEventListener('click', restart);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) restart(); });
    load();
})();
</script>

<!-- Chart Card -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fa-solid fa-chart-line me-2 glc-icon"></i>Cooperative Equity Over Time (12 months)</span>
        <div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:0.82rem;">
            <span class="d-flex align-items-center gap-1">
                <span style="display:inline-block;width:28px;height:3px;background:var(--glc-indigo);border-radius:2px;"></span>
                Historical Equity
            </span>
            <span class="d-flex align-items-center gap-1">
                <span style="display:inline-block;width:28px;height:3px;background:var(--glc-pink);border-radius:2px;border-top:2px dashed var(--glc-pink);"></span>
                Forecast (Estimate)
            </span>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- Disclaimer note -->
        <p class="text-muted small mb-3">
            <i class="fa-solid fa-circle-info me-1 glc-icon"></i>
            <em>Forecasts are estimates based on historical trends using linear regression.
            Actual results may vary depending on member activity and market conditions.</em>
        </p>
        <div style="position: relative; height: 380px;">
            <canvas id="equityChart"></canvas>
        </div>
    </div>
</div>

<!-- Summary Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Members</div>
                <div class="fw-bold fs-4" id="stat-members">—</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Contributions</div>
                <div class="fw-bold fs-4" id="stat-contributions">—</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Active Loans</div>
                <div class="fw-bold fs-4" id="stat-loans">—</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Current Equity</div>
                <div class="fw-bold fs-4 text-success" id="stat-equity">—</div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('equityChart');

    // Fetch analytics data from the API endpoint
    fetch('<?= base_url($dataUrl ?? 'admin/analytics/data') ?>', {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        renderChart(ctx, data);
        // Update stat tiles if provided
        if (data.stats) {
            const fmt = (n) => '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2});
            if (data.stats.total_members !== undefined)
                document.getElementById('stat-members').textContent = data.stats.total_members.toLocaleString();
            if (data.stats.total_contributions !== undefined)
                document.getElementById('stat-contributions').textContent = fmt(data.stats.total_contributions);
            if (data.stats.active_loans !== undefined)
                document.getElementById('stat-loans').textContent = data.stats.active_loans.toLocaleString();
            if (data.stats.current_equity !== undefined)
                document.getElementById('stat-equity').textContent = fmt(data.stats.current_equity);
        }
    })
    .catch(err => {
        console.error('Analytics data fetch error:', err);
        // Render with empty placeholder so the chart frame is still visible
        renderChart(ctx, { labels: [], actual: [], forecast: [], forecastStartIndex: 0 });
    });

    function renderChart(canvas, data) {
        const labels = data.labels || [];
        const actualValues = data.actual || [];
        const forecastValues = data.forecast || [];
        const forecastStartIndex = data.forecastStartIndex ?? actualValues.length;

        // Build a combined label set (historical + forecast months not already in labels)
        const allLabels = labels.concat((data.forecastLabels || []).filter(l => !labels.includes(l)));

        // Build datasets:
        // Dataset 1: Historical — solid navy line, ends at forecastStartIndex
        // Dataset 2: Forecast dashed continuation — gold dashed line, begins at forecastStartIndex
        const historicalData = [];
        const forecastData = [];

        for (let i = 0; i < allLabels.length; i++) {
            if (i < actualValues.length) {
                historicalData.push(actualValues[i]);
                forecastData.push(i === actualValues.length - 1 ? actualValues[i] : null); // connect at boundary
            } else {
                historicalData.push(null);
                const fIdx = i - (actualValues.length - 1);
                forecastData.push(forecastValues[fIdx] !== undefined ? forecastValues[fIdx] : null);
            }
        }

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: allLabels,
                datasets: [
                    {
                        label: 'Historical Equity',
                        data: historicalData,
                        borderColor: '#2622b0',
                        backgroundColor: 'rgba(38,34,176,0.08)',
                        borderWidth: 2.5,
                        pointBackgroundColor: '#2622b0',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true,
                        spanGaps: false,
                    },
                    {
                        label: 'Forecast (Estimate)',
                        data: forecastData,
                        borderColor: '#ff0a7e',
                        backgroundColor: 'rgba(255,10,126,0.07)',
                        borderWidth: 2.5,
                        borderDash: [8, 4],
                        pointBackgroundColor: '#ff0a7e',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointStyle: 'triangle',
                        tension: 0.3,
                        fill: false,
                        spanGaps: false,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed.y;
                                if (val === null) return null;
                                const isForecast = context.dataset.label.includes('Forecast');
                                const prefix = isForecast ? '~ ₱' : '₱';
                                return ' ' + context.dataset.label + ': ' + prefix + val.toLocaleString('en-PH', {minimumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { font: { size: 11 }, maxRotation: 45 }
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: {
                            font: { size: 11 },
                            callback: function(value) {
                                return '₱' + value.toLocaleString('en-PH', {minimumFractionDigits: 0});
                            }
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }
});
</script>

<?= $this->endSection() ?>
