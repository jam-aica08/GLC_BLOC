<?php $this->extend('layouts/main'); ?>
<?php $this->section('content'); ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="fas fa-coins me-2 text-warning"></i>Dividends</h4>
    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#distributeModal">
        <i class="fas fa-plus me-1"></i>Distribute Dividends
    </button>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="d-flex flex-wrap align-items-end gap-3 mb-3">
            <div>
                <label class="form-label small fw-semibold mb-1">Year</label>
                <input type="number" name="year" class="form-control form-control-sm" style="width:110px"
                       value="<?= esc($year) ?>" min="2020" max="2100">
            </div>
            <button class="btn btn-outline-secondary btn-sm">Calculate</button>
        </form>
        <div class="row g-3 text-center">
            <div class="col-sm-4">
                <div class="text-muted small">Profit Pool <?= esc($year) ?> (auto)</div>
                <div class="fw-bold fs-5">₱<?= number_format($plan['profit_pool'], 2) ?></div>
                <div class="text-muted small">Interest earned on loans approved this year</div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Basic share (<?= $plan['basic_count'] ?> accounts)</div>
                <div class="fw-bold fs-5">₱<?= number_format($plan['basic_share'], 2) ?></div>
            </div>
            <div class="col-sm-4">
                <div class="text-muted small">Pioneer share (<?= $plan['pioneer_count'] ?> accounts, +2%)</div>
                <div class="fw-bold fs-5">₱<?= number_format($plan['pioneer_share'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Account</th>
                        <th>Year</th>
                        <th>Profit Pool</th>
                        <th>Basic Share</th>
                        <th>Amount</th>
                        <th>Distribution Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dividends)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No dividend records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dividends as $d): ?>
                            <tr>
                                <td><?= esc($d['dividend_id']) ?></td>
                                <td><?= esc($d['account_number'] ?? $d['account_id']) ?></td>
                                <td><?= esc($d['distribution_year']) ?></td>
                                <td>₱<?= number_format($d['profit_pool'], 2) ?></td>
                                <td>₱<?= number_format($d['dividend_per_account'], 2) ?></td>
                                <td>₱<?= number_format($d['dividend_amount'], 2) ?></td>
                                <td><?= esc($d['distribution_date']) ?></td>
                                <td>
                                    <?php
                                    $badges = ['Pending'=>'warning','Approved'=>'info','Distributed'=>'success'];
                                    $b = $badges[$d['dividend_status']] ?? 'secondary';
                                    ?>
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

<!-- Distribute Modal -->
<div class="modal fade" id="distributeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= base_url('admin/dividends/distribute') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="distribution_year" value="<?= esc($year) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-coins me-2"></i>Distribute <?= esc($year) ?> Dividends</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">The profit pool, ₱<?= number_format($plan['profit_pool'], 2) ?>, will be divided among
                       <?= $plan['basic_count'] + $plan['pioneer_count'] ?> active accounts.</p>
                    <ul class="small mb-3">
                        <li><?= $plan['basic_count'] ?> Basic × ₱<?= number_format($plan['basic_share'], 2) ?></li>
                        <li><?= $plan['pioneer_count'] ?> Pioneer × ₱<?= number_format($plan['pioneer_share'], 2) ?> (2% more)</li>
                    </ul>
                    <label class="form-label fw-semibold">Distribution Date</label>
                    <input type="date" name="distribution_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm" <?= $plan['profit_pool'] > 0 ? '' : 'disabled' ?>>
                        <i class="fas fa-coins me-1"></i>Distribute
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
