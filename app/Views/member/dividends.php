<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="fas fa-coins me-2 text-warning"></i>My Dividends</h4>
</div>

<div class="card shadow-sm" data-search>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Account</th>
                        <th>Year</th>
                        <th>Amount</th>
                        <th>Distribution Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dividends)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No dividend records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dividends as $d): ?>
                            <tr>
                                <td><?= esc($d['account_number'] ?? $d['account_id']) ?></td>
                                <td><?= esc($d['distribution_year']) ?></td>
                                <td>₱<?= number_format($d['dividend_amount'], 2) ?></td>
                                <td><?= esc($d['distribution_date']) ?></td>
                                <td>
                                    <?php $b = ['Pending'=>'warning','Approved'=>'info','Distributed'=>'success'][$d['dividend_status']] ?? 'secondary'; ?>
                                    <span class="badge bg-<?= $b ?>"><?= esc($d['dividend_status']) ?></span>
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
