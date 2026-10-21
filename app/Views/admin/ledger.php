<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="mx-auto" style="max-width:1100px;">
<!-- Page Header -->
<div class="text-center mb-4 d-flex flex-column align-items-center gap-3">
    <div>
        <h4 class="mb-1 fw-bold">Blockchain Ledger</h4>
        <p class="text-muted mb-0 small">Immutable chain of financial records secured with cryptographic hashing.</p>
    </div>
    <!-- Run Integrity Verification -->
    <form action="<?= base_url('admin/ledger/verify') ?>" method="post">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm text-white" style="background:#1a1a2e;"
                onclick="return confirm('Run integrity verification on the entire ledger?')">
            <i class="fa-solid fa-shield-halved me-2" style="color:#f0a500;"></i>Run Integrity Verification
        </button>
    </form>
</div>

<!-- Flash Messages -->
<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Verification Result Flash -->
<?php $verifyResult = session()->getFlashdata('verification_result'); ?>
<?php if ($verifyResult): ?>
    <div class="alert alert-dismissible fade show <?= ($verifyResult['status'] === 'valid') ? 'alert-success' : 'alert-danger' ?>"
         role="alert" style="border-radius:10px;">
        <div class="d-flex align-items-start gap-3">
            <i class="fa-solid <?= ($verifyResult['status'] === 'valid') ? 'fa-shield-check' : 'fa-shield-exclamation' ?> fa-lg mt-1"></i>
            <div>
                <strong><?= ($verifyResult['status'] === 'valid') ? 'Ledger Integrity Verified' : 'Ledger Integrity Compromised' ?></strong>
                <p class="mb-0 small"><?= esc($verifyResult['message'] ?? '') ?></p>
                <?php if (!empty($verifyResult['tampered_ids'])): ?>
                    <p class="mb-0 small mt-1">
                        Tampered record IDs: <code><?= esc(implode(', ', $verifyResult['tampered_ids'])) ?></code>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Ledger Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Total Entries</div>
                <div class="fw-bold fs-4"><?= count($ledgerEntries ?? []) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Valid Entries</div>
                <div class="fw-bold fs-4 text-success">
                    <?= count(array_filter($ledgerEntries ?? [], fn($e) => ($e['status'] ?? 'Valid') === 'Valid')) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small">Tampered Entries</div>
                <div class="fw-bold fs-4 text-danger">
                    <?= count(array_filter($ledgerEntries ?? [], fn($e) => ($e['status'] ?? 'Valid') === 'Tampered')) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Table -->
<div class="card" data-search>
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-link me-2" style="color:#f0a500;"></i>Ledger Chain</span>
        <span class="badge bg-secondary"><?= count($ledgerEntries ?? []) ?> block(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Ledger ID</th>
                        <th class="py-3">Record</th>
                        <th class="py-3">Hash</th>
                        <th class="py-3">Previous Hash</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Recorded At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($ledgerEntries)): ?>
                        <?php foreach ($ledgerEntries as $index => $entry): ?>
                        <?php $isValid = ($entry['status'] ?? 'Valid') === 'Valid'; ?>
                        <tr class="<?= $isValid ? '' : 'table-danger' ?>">
                            <td class="px-4 py-3"><code><?= esc($entry['ledger_id'] ?? $entry['id'] ?? '-') ?></code></td>
                            <td class="py-3">
                                <div><?= esc($entry['record_type'] ?? 'Record') ?> <code>#<?= esc($entry['financial_record_id'] ?? '-') ?></code></div>
                                <?php if (isset($entry['amount'])): ?><div class="small text-muted">₱<?= number_format((float) $entry['amount'], 2) ?></div><?php endif; ?>
                            </td>
                            <td class="py-3">
                                <code class="text-break" style="font-size:0.78rem;" title="<?= esc($entry['hash'] ?? '') ?>" data-bs-toggle="tooltip">
                                    <?= esc(substr($entry['hash'] ?? '', 0, 16)) ?>…
                                </code>
                            </td>
                            <td class="py-3">
                                <?php $prevHash = $entry['previous_hash'] ?? ''; ?>
                                <?php if ($prevHash): ?>
                                    <code style="font-size:0.78rem;color:#6c757d;">
                                        <?= esc(substr($prevHash, 0, 16)) ?>...
                                    </code>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Genesis Block</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3">
                                <?php if ($isValid): ?>
                                    <span class="badge bg-success">
                                        <i class="fa-solid fa-shield-check me-1"></i>Valid
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger">
                                        <i class="fa-solid fa-shield-exclamation me-1"></i>Tampered
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-muted small"><?= esc($entry['recorded_at'] ?? $entry['created_at'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-link fa-2x mb-3 d-block opacity-25"></i>
                                No ledger entries yet. Approved financial records will appear here.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    });
</script>

</div>

<?= $this->endSection() ?>
