<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<?php
/**
 * @var list<array> $rows   members due for review (RetentionService::reviewList)
 * @var list<array> $holds  members on legal hold
 * @var ?array      $lastRun
 * @var \Config\Retention $config
 * @var bool        $cronReady
 */
$stages = [
    'flagged'    => ['Flagged as inactive', 'bg-warning text-dark', "No activity for {$config->inactiveFlagMonths}+ months. Deactivated automatically at {$config->inactiveDeactivateMonths} months unless they become active."],
    'login_data' => ['Sign-in data can be removed', 'bg-info text-dark', "Deactivated and inactive for {$config->inactiveLoginDataMonths}+ months."],
    'anonymize'  => ['Can be anonymized', 'bg-secondary', "The {$config->financialYears}-year financial retention period has ended."],
    'hold'       => ['On legal hold', 'bg-danger', 'Nothing is deactivated, removed or anonymized until the hold is released.'],
];
$counts = array_count_values(array_column($rows, 'stage'));
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold mb-1"><i class="fa-solid fa-box-archive me-2 glc-icon"></i>Data Retention</h1>
        <p class="text-muted small mb-0">Review members under the retention policy. <a href="<?= base_url('privacy') ?>">See the published schedule</a>.</p>
    </div>
    <form method="post" action="<?= base_url('admin/retention/run') ?>" onsubmit="this.querySelector('button').disabled = true;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-rotate me-1"></i>Run review now</button>
    </form>
</div>

<?= view('partials/flash') ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body small">
                <div class="fw-semibold mb-1"><i class="fa-solid fa-clock-rotate-left me-1"></i>Last review</div>
                <?php if ($lastRun): ?>
                    <div><?= esc(date('M j, Y g:i A', strtotime($lastRun['created_at']))) ?> by <?= esc($lastRun['username'] ?? 'system') ?></div>
                    <div class="text-muted"><?= esc($lastRun['details'] ?? '') ?></div>
                <?php else: ?>
                    <div class="text-muted">Not run yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm h-100 <?= $cronReady ? '' : 'border-warning' ?>">
            <div class="card-body small">
                <div class="fw-semibold mb-1"><i class="fa-solid fa-calendar-day me-1"></i>Daily automatic review</div>
                <?php if ($cronReady): ?>
                    <div class="text-success"><i class="fa-solid fa-circle-check me-1"></i>Scheduled every day at 2:00 AM (Philippine time).</div>
                <?php else: ?>
                    <div class="text-warning-emphasis">Not active on this server: the <code>CRON_SECRET</code> setting is missing. Until it is set, use <strong>Run review now</strong>.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold"><i class="fa-solid fa-user-clock me-2"></i>Members due for review</span>
        <span class="small">
            <?php foreach ($stages as $key => [$label, $badge]): ?>
                <span class="badge <?= $badge ?> me-1"><?= esc($label) ?>: <?= (int) ($counts[$key] ?? 0) ?></span>
            <?php endforeach; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:0.85rem;">
            <thead class="table-light">
                <tr><th class="px-3">Member</th><th>Status</th><th>Last activity</th><th>Financial records kept until</th><th>Stage</th><th class="text-end pe-3">Action</th></tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No member is due for review.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): [$label, $badge, $hint] = $stages[$r['stage']]; ?>
                <tr>
                    <td class="px-3">
                        <div class="fw-semibold"><?= esc($r['first_name'] . ' ' . $r['last_name']) ?></div>
                        <small class="text-muted"><?= esc($r['member_number']) ?> · @<?= esc($r['username'] ?? '') ?></small>
                    </td>
                    <td><span class="badge <?= $r['membership_status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= esc($r['membership_status']) ?></span></td>
                    <td><?= esc(date('M j, Y', strtotime($r['last_activity']))) ?></td>
                    <td><?= esc(date('M j, Y', strtotime($r['keep_until']))) ?></td>
                    <td>
                        <span class="badge <?= $badge ?>"><?= esc($label) ?></span>
                        <div class="small text-muted"><?= esc($r['stage'] === 'hold' ? ($r['legal_hold_reason'] ?? $hint) : $hint) ?></div>
                        <?php if ($r['blockers']): ?><div class="small text-danger">Waiting: the member <?= esc(implode(' and ', $r['blockers'])) ?>.</div><?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <?php if ($r['stage'] === 'login_data' && ! $r['blockers']): ?>
                            <form method="post" action="<?= base_url('admin/retention/' . (int) $r['member_id'] . '/remove-login') ?>" class="d-inline-flex gap-1"
                                  onsubmit="return confirm('Remove the username and email of <?= esc($r['first_name'] . ' ' . $r['last_name'], 'js') ?>? They will never be able to sign in with this login again.');">
                                <?= csrf_field() ?>
                                <input type="text" name="confirm" class="form-control form-control-sm" style="width:7rem" placeholder="Type REMOVE" pattern="REMOVE" required aria-label="Type REMOVE to confirm">
                                <button type="submit" class="btn btn-sm btn-outline-danger text-nowrap">Remove sign-in data</button>
                            </form>
                        <?php elseif ($r['stage'] === 'anonymize' && ! $r['blockers']): ?>
                            <form method="post" action="<?= base_url('admin/retention/' . (int) $r['member_id'] . '/anonymize') ?>" class="d-inline-flex gap-1"
                                  onsubmit="return confirm('Replace the name of <?= esc($r['first_name'] . ' ' . $r['last_name'], 'js') ?> with &quot;Former member&quot;? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <input type="text" name="confirm" class="form-control form-control-sm" style="width:8rem" placeholder="Type ANONYMIZE" pattern="ANONYMIZE" required aria-label="Type ANONYMIZE to confirm">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Anonymize</button>
                            </form>
                        <?php elseif ($r['stage'] === 'hold'): ?>
                            <form method="post" action="<?= base_url('admin/retention/hold') ?>" onsubmit="return confirm('Release the legal hold?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="member_id" value="<?= (int) $r['member_id'] ?>">
                                <input type="hidden" name="hold" value="0">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Release hold</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-gavel me-2"></i>Legal hold</div>
    <div class="card-body">
        <p class="small text-muted">Place a hold when a member's records may be needed for a dispute, complaint, audit or investigation.
            While it is set, the member is not deactivated for inactivity and their data cannot be removed or anonymized.</p>
        <form method="post" action="<?= base_url('admin/retention/hold') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="hold" value="1">
            <div class="col-md-3">
                <label for="hold_member" class="form-label small fw-semibold">Member #</label>
                <input type="text" id="hold_member" name="member_number" class="form-control" placeholder="e.g. MEM-001" required>
            </div>
            <div class="col-md-6">
                <label for="hold_reason" class="form-label small fw-semibold">Reason</label>
                <input type="text" id="hold_reason" name="reason" class="form-control" maxlength="255" placeholder="e.g. Unresolved dispute over the 2026 dividend" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-danger w-100"><i class="fa-solid fa-lock me-1"></i>Place hold</button>
            </div>
        </form>
        <?php if ($holds): ?>
            <ul class="small mt-3 mb-0">
                <?php foreach ($holds as $h): ?>
                    <li><strong><?= esc($h['member_number']) ?></strong> <?= esc($h['first_name'] . ' ' . $h['last_name']) ?> — <?= esc($h['legal_hold_reason'] ?? '') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
