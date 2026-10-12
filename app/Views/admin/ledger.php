<?php $this->extend('layouts/main'); ?>
<?php $this->section('content'); ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-link me-2 text-warning"></i>Blockchain Ledger</h4>
    <form method="post" action="/admin/ledger/verify">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-shield-alt me-1"></i>Verify Entire Chain
        </button>
    </form>
</div>

<?php if (session()->getFlashdata('verify_result')): ?>
    <?php $vr = session()->getFlashdata('verify_result'); ?>
    <div class="alert alert-<?= $vr['result'] === 'ok' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <?php if ($vr['result'] === 'ok'): ?>
            <i class="fas fa-check-circle me-2"></i>
            Chain verified: <strong><?= $vr['checked'] ?></strong> entries checked — all valid.
        <?php else: ?>
            <i class="fas fa-exclamation-triangle me-2"></i>
            Chain tampered at sequence <strong>#<?= esc($vr['first_failure']['sequence'] ?? '?') ?></strong>.
            <?= $vr['checked'] ?> entries checked; <?= $vr['tampered'] ?> tampered.
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small">
                <thead class="table-dark">
                    <tr>
                        <th>Seq</th>
                        <th>Rec ID</th>
                        <th>Transaction Hash</th>
                        <th>Previous Hash</th>
                        <th>Recorded At</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No ledger entries found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($entries as $e): ?>
                            <tr>
                                <td><?= esc($e['ledger_sequence']) ?></td>
                                <td><?= esc($e['financial_record_id']) ?></td>
                                <td class="font-monospace text-truncate" style="max-width:160px" title="<?= esc($e['transaction_hash']) ?>">
                                    <?= esc(substr($e['transaction_hash'], 0, 16)) ?>…
                                </td>
                                <td class="font-monospace text-truncate" style="max-width:140px" title="<?= esc($e['previous_hash'] ?? 'GENESIS') ?>">
                                    <?= $e['previous_hash'] ? esc(substr($e['previous_hash'], 0, 14)) . '…' : '<span class="badge bg-secondary">GENESIS</span>' ?>
                                </td>
                                <td><?= esc($e['recorded_at']) ?></td>
                                <td>
                                    <?php if ($e['verification_status'] === 'Valid'): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Valid</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Tampered</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
