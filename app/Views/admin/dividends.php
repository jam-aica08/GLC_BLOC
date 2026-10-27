<?php $this->extend('layouts/main'); ?>
<?php $this->section('content'); ?>
<?php
$peso     = fn($n, $d = 2) => '₱' . number_format((float) $n, $d);
$canPlan  = $available && $plan['errors'] === [] && ! $plan['existing'] && ! $plan['legacy_paid'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><i class="fas fa-coins me-2 glc-icon"></i>Dividends</h1>
    <button class="btn btn-brand btn-sm" data-bs-toggle="modal" data-bs-target="#distributeModal">
        <i class="fas fa-plus me-1"></i>Distribute Dividends
    </button>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>
<?php if (! $available): ?>
    <div class="alert alert-warning">Distributions need a database update.
        <a href="<?= base_url('admin/settings') ?>" class="alert-link">Open Settings → Apply Database Updates</a>.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="d-flex flex-wrap align-items-end gap-3 mb-3">
            <div>
                <label for="year" class="form-label small fw-semibold mb-1">Year</label>
                <input type="number" id="year" name="year" class="form-control form-control-sm" style="width:110px"
                       value="<?= esc($year) ?>" min="2000" max="<?= date('Y') ?>" required>
            </div>
            <button class="btn btn-outline-secondary btn-sm">Calculate</button>
        </form>
        <div class="row g-3 text-center align-items-center">
            <div class="col-md-6">
                <div class="pool-hero text-start">
                    <div class="pool-label"><i class="fa-solid fa-sack-dollar me-2"></i>Profit pool <?= esc($year) ?></div>
                    <div class="pool-value"><?= $peso($plan['profit_pool']) ?></div>
                    <div class="pool-sub">Interest earned on loans approved this year · calculated automatically</div>
                    <div class="pool-sub mt-1">Administration <?= rtrim(rtrim(number_format($plan['admin_share_rate'], 2), '0'), '.') ?>%: <?= $peso($plan['admin_share']) ?> ·
                        Members <?= rtrim(rtrim(number_format(100 - $plan['admin_share_rate'], 2), '0'), '.') ?>%: <?= $peso($plan['members_pool']) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Basic share (<?= $plan['basic_count'] ?> accounts)</div>
                <div class="fw-bold fs-5"><?= $peso($plan['basic_share']) ?></div>
                <?php if ($plan['basic_share'] > 0): ?><div class="text-muted small">exact <?= $peso($plan['basic_share'], 6) ?></div><?php endif; ?>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Pioneer share (<?= $plan['pioneer_count'] ?> accounts, +2%)</div>
                <div class="fw-bold fs-5"><?= $peso($plan['pioneer_share']) ?></div>
                <?php if ($plan['pioneer_share'] > 0): ?>
                    <div class="text-muted small"><?= $plan['pioneer_count'] ? 'exact ' . $peso($plan['pioneer_share'], 6) : 'Rate only — no Pioneer payouts due' ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($plan['errors'] !== []): ?>
            <div class="alert alert-warning small mb-0 mt-3"><?= esc(implode(' ', $plan['errors'])) ?></div>
        <?php elseif ($plan['existing']): ?>
            <div class="alert alert-info small mb-0 mt-3">Dividends for <?= esc($year) ?> are already
                <?= $plan['existing']['status'] === 'Approved' ? 'distributed' : 'waiting for approval' ?> (distribution #<?= (int) $plan['existing']['distribution_id'] ?>).</div>
        <?php elseif ($plan['legacy_paid']): ?>
            <div class="alert alert-info small mb-0 mt-3">Dividends for <?= esc($year) ?> were already recorded.</div>
        <?php else: ?>
            <div class="text-muted small mt-3 text-center">
                Each Basic account counts as 1.00 share and each Pioneer account as 1.02 · Total to members: <?= $peso($plan['total_distributed']) ?>
                <br>Eligible: accounts with ₱<?= number_format($plan['required'], 2) ?> of approved contributions for <?= esc($year) ?>
                (<?= count($plan['accounts']) ?> eligible<?= $plan['below_count'] ? ', ' . (int) $plan['below_count'] . ' below the requirement and not included' : '' ?>).
                <?php if ($plan['inactive_count'] > 0): ?><?= (int) $plan['inactive_count'] ?> deactivated account(s) included: deactivation does not disqualify an account that completed its contributions.<?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (! empty($distributions)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="fas fa-layer-group me-2 glc-icon"></i>Distributions</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:.875rem;">
            <thead class="table-light">
                <tr><th>#</th><th>Year</th><th class="text-end">Profit Pool</th><th class="text-end">Administration</th><th>Accounts</th><th class="text-end">Basic / Pioneer share</th><th class="text-end">Total</th><th>Approvals</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($distributions as $dist): ?>
                    <?php
                        $status = $dist['status'];
                        $label  = ['Pending' => 'Waiting for approval', 'Approved' => 'Distributed', 'Rejected' => 'Rejected'][$status];
                        $badge  = ['Pending' => 'bg-warning text-dark', 'Approved' => 'bg-success', 'Rejected' => 'bg-danger'][$status];
                    ?>
                    <tr>
                        <td><code>#<?= (int) $dist['distribution_id'] ?></code></td>
                        <td><a href="<?= base_url('admin/dividends?year=' . (int) $dist['distribution_year']) ?>"><?= (int) $dist['distribution_year'] ?></a></td>
                        <td class="text-end"><?= $peso($dist['profit_pool']) ?></td>
                        <td class="text-end"><?= ! empty($dist['admin_share_rate']) && (float) $dist['admin_share_rate'] > 0 ? $peso($dist['admin_share']) . ' <span class="text-muted small">(' . rtrim(rtrim(number_format((float) $dist['admin_share_rate'], 2), '0'), '.') . '%)</span>' : '—' ?></td>
                        <td><?= (int) $dist['basic_count'] ?> Basic, <?= (int) $dist['pioneer_count'] ?> Pioneer</td>
                        <td class="text-end"><?= $peso($dist['basic_share']) ?> / <?= $peso($dist['pioneer_share']) ?></td>
                        <td class="text-end fw-semibold"><?= $peso($dist['total_distributed']) ?></td>
                        <td class="small text-muted">
                            Created by <?= esc($dist['created_by_name'] ?? '—') ?>
                            <?php if ($status === 'Approved'): ?><br>Approved by <?= esc($dist['approver_name'] ?? '—') ?><?= ! empty($dist['approved_at']) ? ', ' . esc(date('M j, Y g:i A', strtotime($dist['approved_at']))) : '' ?><?php endif; ?>
                            <?php if ($dist['rejected_by_name']): ?><br>Rejected by <?= esc($dist['rejected_by_name']) ?><?php endif; ?>
                        </td>
                        <td><span class="badge <?= $badge ?>"><?= esc($label) ?></span></td>
                        <td class="text-end">
                            <?php if ($status === 'Pending'): ?>
                                <div class="d-inline-flex gap-1">
                                    <form method="post" action="<?= base_url('admin/dividends/' . (int) $dist['distribution_id'] . '/approve') ?>">
                                        <?= csrf_field() ?><input type="hidden" name="year" value="<?= (int) $dist['distribution_year'] ?>">
                                        <button class="btn btn-sm btn-success"><i class="fa-solid fa-check me-1"></i>Approve</button>
                                    </form>
                                    <form method="post" action="<?= base_url('admin/dividends/' . (int) $dist['distribution_id'] . '/reject') ?>">
                                        <?= csrf_field() ?><input type="hidden" name="year" value="<?= (int) $dist['distribution_year'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-xmark me-1"></i>Reject</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">A distribution is recorded and added to the blockchain ledger only after an administrator approves it.</div>
</div>
<?php endif; ?>

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
                        <tr><td colspan="8" class="text-center py-4 text-muted">No dividend records for <?= esc($year) ?>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dividends as $d): ?>
                            <tr>
                                <td>
                                    <?= esc($d['dividend_id']) ?>
                                    <?php if (! empty($d['distribution_id'])): ?><div class="small text-muted">Distribution #<?= (int) $d['distribution_id'] ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <?= esc($d['account_number'] ?? $d['account_id']) ?> <span class="small text-muted"><?= esc($d['account_type'] ?? '') ?></span>
                                    <div class="small text-muted"><?= esc($d['member_name'] ?? '') ?></div>
                                </td>
                                <td><?= esc($d['distribution_year']) ?></td>
                                <td><?= $peso($d['profit_pool']) ?></td>
                                <td><?= $peso($d['dividend_per_account']) ?></td>
                                <td class="fw-semibold"><?= $peso($d['dividend_amount']) ?></td>
                                <td><?= esc($d['distribution_date']) ?></td>
                                <td>
                                    <?php
                                    $badges = ['Pending' => 'warning text-dark', 'Approved' => 'info', 'Distributed' => 'success', 'Rejected' => 'danger'];
                                    $b = $badges[$d['dividend_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $b ?>"><?= esc($d['dividend_status'] === 'Pending' ? 'Waiting for approval' : $d['dividend_status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Distribute Modal: review the figures, then confirm -->
<div class="modal fade" id="distributeModal" tabindex="-1" aria-labelledby="distributeModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="<?= base_url('admin/dividends/distribute') ?>" onsubmit="this.querySelector('[type=submit]').disabled = true;">
                <?= csrf_field() ?>
                <input type="hidden" name="distribution_year" value="<?= esc($year) ?>">
                <input type="hidden" name="expected_pool" value="<?= esc(number_format($plan['profit_pool'], 2, '.', '')) ?>">
                <input type="hidden" name="expected_accounts" value="<?= count($plan['accounts']) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="distributeModalLabel"><i class="fas fa-coins me-2"></i>Distribute <?= esc($year) ?> Dividends</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (! $canPlan): ?>
                        <div class="alert alert-warning small mb-0">
                            <?php if (! $available): ?>Apply Database Updates in Settings first.
                            <?php elseif ($plan['errors'] !== []): ?><?= esc(implode(' ', $plan['errors'])) ?>
                            <?php else: ?>Dividends for <?= esc($year) ?> have already been distributed or are waiting for approval.<?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="table table-sm mb-3">
                            <tbody>
                                <tr><th class="fw-normal text-muted">Profit pool</th><td class="text-end fw-semibold"><?= $peso($plan['profit_pool']) ?></td></tr>
                                <tr><th class="fw-normal text-muted">Administration (<?= rtrim(rtrim(number_format($plan['admin_share_rate'], 2), '0'), '.') ?>%)</th><td class="text-end"><?= $peso($plan['admin_share']) ?></td></tr>
                                <tr><th class="fw-normal text-muted">Members' pool</th><td class="text-end"><?= $peso($plan['members_pool']) ?></td></tr>
                                <tr><th class="fw-normal text-muted">Basic accounts</th><td class="text-end"><?= $plan['basic_count'] ?> × <?= $peso($plan['basic_share']) ?></td></tr>
                                <tr><th class="fw-normal text-muted">Pioneer accounts (+2%)</th><td class="text-end"><?= $plan['pioneer_count'] ?> × <?= $peso($plan['pioneer_share']) ?></td></tr>
                                <tr><th>Total to members</th><td class="text-end fw-bold"><?= $peso($plan['total_distributed']) ?></td></tr>
                            </tbody>
                        </table>
                        <p class="small text-muted">Amounts are rounded to the centavo; any leftover centavos go to the accounts with the largest remainders, so the total equals the members' pool exactly.
                            The distribution is saved as <strong>waiting for approval</strong>; an administrator must approve it before it is recorded.</p>
                        <label for="distribution_date" class="form-label fw-semibold">Distribution Date</label>
                        <input type="date" id="distribution_date" name="distribution_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand btn-sm" <?= $canPlan ? '' : 'disabled' ?>>
                        <i class="fas fa-coins me-1"></i>Confirm Distribution
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
