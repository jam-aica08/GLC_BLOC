<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1"><i class="fas fa-link me-2 text-warning"></i>My Ledger</h4>
        <p class="text-muted small mb-0">Your transactions recorded on the blockchain ledger.</p>
    </div>
</div>

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
                    <?php if (empty($ledger_entries)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No ledger entries found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger_entries as $e): ?>
                            <tr>
                                <td><?= esc($e['ledger_sequence']) ?></td>
                                <td><?= esc($e['financial_record_id']) ?></td>
                                <td class="font-monospace" title="<?= esc($e['transaction_hash']) ?>">
                                    <?= esc(substr($e['transaction_hash'], 0, 16)) ?>…
                                </td>
                                <td class="font-monospace" title="<?= esc($e['previous_hash'] ?? 'GENESIS') ?>">
                                    <?php if ($e['previous_hash']): ?>
                                        <?= esc(substr($e['previous_hash'], 0, 14)) ?>…
                                    <?php else: ?>
                                        <span class="badge bg-secondary">GENESIS</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc($e['recorded_at']) ?></td>
                                <td>
                                    <?php if ($e['verification_status'] === 'Valid'): ?>
                                        <span class="badge bg-success">Valid</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Tampered</span>
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

<?= $this->endSection() ?>
