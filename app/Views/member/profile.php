<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="fas fa-user-circle me-2 glc-icon"></i>My Profile</h4>
</div>

<?= view('partials/flash') ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold bg-white">Account Information</div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <tr>
                        <th class="text-muted" style="width:45%">Username</th>
                        <td><?= esc($member['username'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Email</th>
                        <td><?= esc($member['email'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Role</th>
                        <td><span class="badge bg-primary"><?= esc($member['role_name'] ?? 'Member') ?></span></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Status</th>
                        <td>
                            <span class="badge bg-<?= ($member['user_status'] ?? '') === 'Active' ? 'success' : 'secondary' ?>">
                                <?= esc($member['user_status'] ?? '') ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold bg-white">Member Details</div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <tr>
                        <th class="text-muted" style="width:45%">Member #</th>
                        <td><?= esc($member['member_number'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Full Name</th>
                        <td><?= esc(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Member Since</th>
                        <td><?= esc($member['membership_date'] ?? '') ?></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Membership Status</th>
                        <td>
                            <span class="badge bg-<?= ($member['membership_status'] ?? '') === 'Active' ? 'success' : 'secondary' ?>">
                                <?= esc($member['membership_status'] ?? '') ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header fw-semibold bg-white d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-wallet me-2"></i>My Accounts</span>
        <?php if (! empty($hasPendingRequest)): ?>
            <span class="small text-muted"><i class="fa-solid fa-hourglass-half me-1"></i>Request waiting for approval</span>
        <?php else: ?>
            <button type="button" class="btn btn-sm btn-brand" data-bs-toggle="modal" data-bs-target="#openAccountModal">
                <i class="fa-solid fa-plus me-1"></i>Request New Account
            </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Account #</th>
                        <th class="py-3">Type</th>
                        <th class="py-3">Opened</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Paid</th>
                        <th class="py-3">Balance Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($accounts ?? []) as $acc): $st = $acc['standing']; ?>
                    <tr>
                        <td class="px-4 py-3"><code><?= esc($acc['account_number']) ?></code></td>
                        <td class="py-3"><?= esc($acc['account_type']) ?></td>
                        <td class="py-3"><?= esc($acc['account_start_date']) ?></td>
                        <td class="py-3">
                            <?php if ($acc['account_status'] === 'Pending'): ?>
                                <span class="badge bg-warning text-dark">Joining — ₱<?= number_format($st['join_balance'], 2) ?> left</span>
                            <?php else: ?>
                                <span class="badge <?= $acc['account_status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= esc($acc['account_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">₱<?= number_format($st['paid'], 2) ?></td>
                        <td class="py-3 <?= $st['due_balance'] > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">₱<?= number_format($st['due_balance'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php foreach (($requests ?? []) as $req): ?>
                    <tr class="text-muted">
                        <td class="px-4 py-3">—</td>
                        <td class="py-3"><?= esc($req['account_type']) ?></td>
                        <td class="py-3">Requested <?= esc(date('Y-m-d', strtotime($req['created_at']))) ?></td>
                        <td class="py-3">
                            <?php if ($req['status'] === 'Pending'): ?>
                                <span class="badge bg-secondary">Waiting for approval</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Request rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">—</td>
                        <td class="py-3">—</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (in_array('Pending', array_column($accounts ?? [], 'account_status'), true)): ?>
        <div class="card-footer bg-white small text-muted">
            Accounts marked <em>Joining</em> activate once the amount shown is paid.
            <a href="<?= base_url('member/contributions') ?>">Contribute</a>
        </div>
    <?php endif; ?>
</div>

<!-- Request Account Modal -->
<div class="modal fade" id="openAccountModal" tabindex="-1" aria-labelledby="openAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="openAccountModalLabel">
                    <i class="fa-solid fa-wallet me-2 glc-icon"></i>Request New Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="<?= base_url('member/profile/accounts/add') ?>">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">An admin reviews your request. Once it is approved you can pay for the new account; it is activated once January to the month of approval is paid.</p>
                    <label for="open_account_type" class="form-label fw-semibold small">Account Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="open_account_type" name="account_type" required>
                        <option value="" disabled selected>Select account type</option>
                        <option value="Pioneer">Pioneer</option>
                        <option value="Basic">Basic</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-paper-plane me-2"></i>Send Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
