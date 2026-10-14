<?= $this->extend($layout ?? 'layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Analytics</h4>
        <p class="text-muted mb-0 small">Cooperative equity growth analysis and forecast.</p>
    </div>
    <span class="badge" style="background:#1a1a2e;color:#f0a500;padding:8px 14px;border-radius:8px;font-size:0.8rem;">
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

<!-- Chart Card -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fa-solid fa-chart-line me-2" style="color:#f0a500;"></i>Cooperative Equity Over Time</span>
        <div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:0.82rem;">
            <span class="d-flex align-items-center gap-1">
                <span style="display:inline-block;width:28px;height:3px;background:#1a1a2e;border-radius:2px;"></span>
                Historical Equity
            </span>
            <span class="d-flex align-items-center gap-1">
                <span style="display:inline-block;width:28px;height:3px;background:#f0a500;border-radius:2px;border-top:2px dashed #f0a500;"></span>
                Forecast (Estimate)
            </span>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- Disclaimer note -->
        <p class="text-muted small mb-3">
            <i class="fa-solid fa-circle-info me-1" style="color:#f0a500;"></i>
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
                        borderColor: '#1a1a2e',
                        backgroundColor: 'rgba(26,26,46,0.08)',
                        borderWidth: 2.5,
                        pointBackgroundColor: '#1a1a2e',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true,
                        spanGaps: false,
                    },
                    {
                        label: 'Forecast (Estimate)',
                        data: forecastData,
                        borderColor: '#f0a500',
                        backgroundColor: 'rgba(240,165,0,0.07)',
                        borderWidth: 2.5,
                        borderDash: [8, 4],
                        pointBackgroundColor: '#f0a500',
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
