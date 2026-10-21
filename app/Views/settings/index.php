<?= $this->extend($layout) ?>
<?= $this->section('content') ?>

<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="fa-solid fa-gear me-2 glc-icon"></i>Settings</h4>
    <p class="text-muted small mb-0">Signed in as <strong><?= esc($user['username'] ?? '') ?></strong> (<?= esc($user['email'] ?? '') ?>)</p>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= esc(session()->getFlashdata('success')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php $base = $isAdmin ? 'admin/settings' : 'member/settings'; ?>

<?php if ($isAdmin && $dbStatus !== null): ?>
    <?php $needs = $dbStatus['pending'] || $dbStatus['problems'] || $dbStatus['error']; ?>
    <div class="card shadow-sm mb-4 <?= $needs ? 'border-warning' : '' ?>">
        <div class="card-header fw-semibold <?= $needs ? 'bg-warning-subtle' : 'bg-white' ?>">
            <i class="fa-solid fa-database me-2"></i>Database
        </div>
        <div class="card-body">
            <?php if (! $needs): ?>
                <p class="mb-3 text-success"><i class="fa-solid fa-circle-check me-1"></i>The database is up to date.</p>
            <?php else: ?>
                <p class="mb-2">The database needs updating for the latest features. Your data is kept.</p>
                <ul class="small mb-3">
                    <?php foreach ($dbStatus['pending'] as $name): ?><li>Update: <?= esc($name) ?></li><?php endforeach; ?>
                    <?php foreach ($dbStatus['problems'] as $p): ?><li>Fix: <?= esc($p) ?></li><?php endforeach; ?>
                    <?php if ($dbStatus['error']): ?><li>Could not check: <?= esc($dbStatus['error']) ?></li><?php endif; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?= base_url($base . '/updates') ?>" onsubmit="this.querySelector('button').disabled = true;">
                <?= csrf_field() ?>
                <button type="submit" class="btn <?= $needs ? 'btn-warning' : 'btn-outline-secondary' ?>">
                    <i class="fa-solid fa-rotate me-2"></i>Apply Database Updates
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($isAdmin && ! empty($recentErrors)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Recent Errors (today)</div>
        <div class="card-body">
            <p class="small text-muted">If a page shows "Whoops", the reason appears here. Send a screenshot of this box when reporting a problem.</p>
            <ul class="small mb-0" style="word-break: break-word;">
                <?php foreach ($recentErrors as $line): ?><li><code><?= esc($line) ?></code></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-key me-2"></i>Change Password</div>
            <div class="card-body">
                <form method="post" action="<?= base_url($base . '/password') ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="current_password" class="form-label small fw-semibold">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label for="new_password" class="form-label small fw-semibold">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                        <div class="form-text">At least 8 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label small fw-semibold">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isSuper): ?>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-user-shield me-2"></i>Add Administrator</div>
            <div class="card-body">
                <p class="text-muted small">Any administrator can approve contributions, payments, loans and dividends.</p>
                <form method="post" action="<?= base_url($base . '/admins') ?>" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="admin_username" class="form-label small fw-semibold">Username</label>
                        <input type="text" class="form-control" id="admin_username" name="username" minlength="3" required>
                    </div>
                    <div class="mb-3">
                        <label for="admin_email" class="form-label small fw-semibold">Email</label>
                        <input type="email" class="form-control" id="admin_email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="admin_password" class="form-label small fw-semibold">Temporary Password</label>
                        <input type="password" class="form-control" id="admin_password" name="password" minlength="8" required autocomplete="new-password">
                        <div class="form-text">Ask the new admin to change it in Settings after signing in.</div>
                    </div>
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-user-plus me-2"></i>Create Admin
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($isSuper): ?>
    <div class="card shadow-sm mt-4 border-danger">
        <div class="card-header fw-semibold bg-danger-subtle text-danger"><i class="fa-solid fa-trash-can me-2"></i>Reset Data</div>
        <div class="card-body">
            <p class="small mb-3">Permanently deletes all <strong>loans, installments, loan payments, contributions, dividends, financial records and blockchain ledger entries</strong>.
                Administrator logins are always kept. This cannot be undone.</p>
            <form method="post" action="<?= base_url($base . '/reset') ?>" autocomplete="off"
                  onsubmit="return confirm('Delete the selected data permanently? This cannot be undone.') && !(this.querySelector('button').disabled = true);">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="scope" id="scope_tx" value="transactions" checked>
                        <label class="form-check-label small" for="scope_tx">Transactions only — keep members and their accounts</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="scope" id="scope_all" value="all">
                        <label class="form-check-label small" for="scope_all">Everything — also delete members, their accounts and member logins</label>
                    </div>
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label for="reset_confirm" class="form-label small fw-semibold">Type RESET to confirm</label>
                        <input type="text" class="form-control" id="reset_confirm" name="confirm" pattern="RESET" required>
                    </div>
                    <div class="col-md-4">
                        <label for="reset_password" class="form-label small fw-semibold">Your Password</label>
                        <input type="password" class="form-control" id="reset_password" name="password" required autocomplete="current-password">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-danger w-100"><i class="fa-solid fa-trash-can me-1"></i>Reset Data</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?= $this->endSection() ?>
