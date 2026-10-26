<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<?php
use App\Services\DataImportService;

$sheetIcons = ['Members' => 'fa-users', 'Accounts' => 'fa-wallet', 'Contributions' => 'fa-piggy-bank', 'Loans' => 'fa-hand-holding-dollar', 'Loan Payments' => 'fa-money-bill-wave'];
$r = $result;
?>
<style>
    .step { display: flex; gap: 14px; align-items: flex-start; }
    .step-num { width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; background: var(--glc-gradient); }
    .sheet-chip { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--glc-border); border-radius: 999px; padding: 4px 12px; font-size: .82rem; background: #fff; }
    .sheet-chip i { color: var(--glc-indigo); }
    .count-tile { border: 1px solid var(--glc-border); border-radius: 12px; padding: 12px 14px; background: #fff; height: 100%; }
    .count-tile .n { font-size: 1.4rem; font-weight: 700; }
    .count-tile .l { font-size: .8rem; color: var(--glc-muted); }
    .drop { border: 2px dashed rgba(var(--glc-indigo-rgb), .35); border-radius: 14px; padding: 22px; text-align: center; background: rgba(var(--glc-indigo-rgb), .03); transition: background .15s ease, border-color .15s ease; }
    .drop.is-over { background: rgba(var(--glc-indigo-rgb), .08); border-color: var(--glc-indigo); }
    .drop input[type=file] { max-width: 100%; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1 fw-bold">Import data</h1>
        <p class="text-muted mb-0 small">Bring your cooperative's existing members, accounts, contributions, loans and payments in from Excel.</p>
    </div>
</div>

<?php if ($r && $r['done']): $d = $r['done']; ?>
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
        <i class="fa-solid fa-circle-check mt-1"></i>
        <div>
            <strong>Import complete.</strong>
            <?= implode(', ', array_map(fn($k, $v) => $v . ' ' . strtolower($k), array_keys($d['counts']), $d['counts'])) ?> saved as approved records,
            with <?= (int) $d['ledger'] ?> new blockchain ledger entries.
            <a href="<?= base_url('admin/members') ?>" class="alert-link">View members</a> ·
            <a href="<?= base_url('admin/reports') ?>" class="alert-link">Open reports</a>
        </div>
    </div>
    <?php if ($d['passwords'] !== []): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa-solid fa-key me-2 glc-icon"></i>Generated passwords</span>
                <button type="button" class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
            </div>
            <div class="card-body pb-0">
                <p class="small mb-2"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i><strong>Shown only this once.</strong> Print or copy them now and give each member their own; they can change it under Settings after signing in.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Member</th><th>Username</th><th>Password</th></tr></thead>
                    <tbody>
                        <?php foreach ($d['passwords'] as $p): ?>
                            <tr><td><?= esc($p['member']) ?></td><td><code><?= esc($p['username']) ?></code></td><td><code><?= esc($p['password']) ?></code></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php elseif ($r && $r['errors'] === [] && $r['counts'] !== []): ?>
    <div class="alert alert-success d-flex gap-2 align-items-start" role="status">
        <i class="fa-solid fa-circle-check mt-1"></i>
        <div><strong><?= esc($r['name']) ?> is ready to import.</strong> No errors found. Choose the file again and press <em>Check and import</em> to save it.</div>
    </div>
<?php endif; ?>

<?php if ($r && $r['counts'] !== [] && ! $r['done']): ?>
    <div class="row g-2 mb-4">
        <?php foreach ($r['counts'] as $sheet => $n): ?>
            <div class="col-6 col-md-4 col-xl">
                <div class="count-tile"><div class="l"><i class="fa-solid <?= $sheetIcons[$sheet] ?> me-1"></i><?= esc($sheet) ?></div><div class="n"><?= (int) $n ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($r && $r['errors'] !== []): ?>
    <div class="card mb-4 border-danger">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="text-danger"><i class="fa-solid fa-circle-xmark me-2"></i><?= count($r['errors']) ?> problem(s) to fix<?= $r['name'] ? ' in ' . esc($r['name']) : '' ?></span>
            <span class="small fw-normal text-muted">Nothing was saved.</span>
        </div>
        <div class="table-responsive" style="max-height:420px;">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Sheet</th><th>Row</th><th>Column</th><th>Problem</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($r['errors'], 0, 300) as $e): ?>
                        <tr><td><?= esc($e['sheet'] ?: '—') ?></td><td><?= $e['row'] ?: '—' ?></td><td><?= $e['column'] ? '<code>' . esc($e['column']) . '</code>' : '—' ?></td><td><?= esc($e['message']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($r['errors']) > 300): ?><div class="card-body py-2 small text-muted">Showing the first 300. Fix these and check again.</div><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($r && $r['warnings'] !== []): ?>
    <details class="card mb-4">
        <summary class="card-header" style="cursor:pointer;"><i class="fa-solid fa-triangle-exclamation me-2 text-warning"></i><?= count($r['warnings']) ?> note(s) to review <span class="small fw-normal text-muted">— these do not stop the import</span></summary>
        <div class="table-responsive" style="max-height:320px;">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Sheet</th><th>Row</th><th>Column</th><th>Note</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($r['warnings'], 0, 300) as $w): ?>
                        <tr><td><?= esc($w['sheet']) ?></td><td><?= (int) $w['row'] ?></td><td><code><?= esc($w['column']) ?></code></td><td><?= esc($w['message']) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-body">
                <div class="step mb-4">
                    <span class="step-num">1</span>
                    <div>
                        <h6 class="fw-bold mb-1">Download the template</h6>
                        <p class="small text-muted mb-2">An Excel workbook with one sheet per kind of record and an Instructions sheet explaining every column.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-brand btn-sm" href="<?= base_url('admin/import/template') ?>"><i class="fa-solid fa-download me-1"></i>Template (.xlsx)</a>
                            <a class="btn btn-outline-brand btn-sm" href="<?= base_url('admin/import/sample') ?>"><i class="fa-solid fa-file-excel me-1"></i>Filled-in sample</a>
                        </div>
                    </div>
                </div>
                <div class="step mb-4">
                    <span class="step-num">2</span>
                    <div>
                        <h6 class="fw-bold mb-1">Fill in your records</h6>
                        <p class="small text-muted mb-2">Use your own IDs in <code>member_ref</code>, <code>account_ref</code> and <code>loan_ref</code> to link the sheets. Leave a sheet empty if you have nothing for it.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (DataImportService::SHEETS as $sheet => $cols): ?>
                                <span class="sheet-chip"><i class="fa-solid <?= $sheetIcons[$sheet] ?>"></i><?= esc($sheet) ?> <span class="text-muted"><?= count($cols) ?> columns</span></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="step">
                    <span class="step-num">3</span>
                    <div class="flex-grow-1">
                        <h6 class="fw-bold mb-1">Upload and check</h6>
                        <p class="small text-muted mb-2">Every row is checked first. If anything is wrong you get a list of the exact rows to fix, and nothing is saved.</p>
                        <form method="post" action="<?= base_url('admin/import') ?>" enctype="multipart/form-data" id="import-form">
                            <?= csrf_field() ?>
                            <label class="drop d-block mb-3" id="drop">
                                <i class="fa-solid fa-cloud-arrow-up fa-2x mb-2 text-brand"></i>
                                <span class="d-block fw-semibold mb-2">Choose or drop your .xlsx file</span>
                                <input type="file" name="workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control form-control-sm mx-auto" style="width:min(360px,100%);" required>
                                <span class="d-block small text-muted mt-2">Up to 4 MB</span>
                            </label>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" name="action" value="check" class="btn btn-outline-brand"><i class="fa-solid fa-magnifying-glass me-1"></i>Check file</button>
                                <button type="submit" name="action" value="import" class="btn btn-gradient js-import"><i class="fa-solid fa-file-import me-1"></i>Check and import</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="fa-solid fa-circle-info me-2 glc-icon"></i>How records are saved</div>
            <div class="card-body small">
                <ul class="mb-0 ps-3">
                    <li class="mb-1">Everything is saved as <strong>already approved</strong> and chained into the blockchain ledger in date order.</li>
                    <li class="mb-1">Accounts become <strong>Active</strong> once January up to their opening month is paid, as in the app.</li>
                    <li class="mb-1">Each loan gets its monthly schedule from the release date; payments fill the oldest unpaid month first, as of the day they were paid.</li>
                    <li class="mb-1">Dividends are not imported: once loans are in, distribute past years from the <a href="<?= base_url('admin/dividends') ?>">Dividends</a> page.</li>
                    <li>All or nothing: one bad row stops the whole file.</li>
                </ul>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fa-solid fa-broom me-2 glc-icon"></i>Replacing the demo data?</div>
            <div class="card-body small">
                <p class="mb-2">The system may still hold sample records. Remove them first with <strong>Data reset → Everything</strong> in Settings, then import your own file.</p>
                <a class="btn btn-sm btn-outline-secondary" href="<?= base_url('admin/settings') ?>"><i class="fa-solid fa-gear me-1"></i>Open Settings</a>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('import-form');
    const drop = document.getElementById('drop');
    const input = form.querySelector('input[type=file]');
    ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', e => { if (e.dataTransfer.files.length) input.files = e.dataTransfer.files; });
    form.addEventListener('submit', function (e) {
        const importing = e.submitter && e.submitter.value === 'import';
        if (importing && !confirm('Import every record in this file? If the check finds no errors, they are saved as approved and cannot be undone except by Data reset.')) {
            e.preventDefault();
            return;
        }
        form.querySelectorAll('button[type=submit]').forEach(b => { b.disabled = true; });
        if (e.submitter) {
            const keep = document.createElement('input');
            keep.type = 'hidden'; keep.name = 'action'; keep.value = e.submitter.value;
            form.appendChild(keep);
            e.submitter.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (importing ? 'Importing…' : 'Checking…');
        }
    });
})();
</script>

<?= $this->endSection() ?>
