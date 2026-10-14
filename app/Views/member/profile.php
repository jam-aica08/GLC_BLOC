<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="fas fa-user-circle me-2 text-warning"></i>My Profile</h4>
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
        <button type="button" class="btn btn-sm text-white" style="background:#1a1a2e;" data-bs-toggle="modal" data-bs-target="#openAccountModal">
            <i class="fa-solid fa-plus me-1"></i>Open New Account
        </button>
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
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Open Account Modal -->
<div class="modal fade" id="openAccountModal" tabindex="-1" aria-labelledby="openAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a1a2e;">
                <h5 class="modal-title text-white" id="openAccountModalLabel">
                    <i class="fa-solid fa-wallet me-2" style="color:#f0a500;"></i>Open New Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="<?= base_url('member/profile/accounts/add') ?>">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <?php $catchUp = (int) date('n') * \App\Models\CooperativeAccountModel::MONTHLY_CONTRIBUTION; ?>
                    <div class="alert alert-info small">
                        Every account contributes ₱<?= number_format(\App\Models\CooperativeAccountModel::MONTHLY_CONTRIBUTION, 2) ?> per month.
                        A new account joins the cooperative after contributing from January:
                        <strong>₱<?= number_format($catchUp, 2) ?></strong> for this year so far.
                    </div>
                    <label for="open_account_type" class="form-label fw-semibold small">Account Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="open_account_type" name="account_type" required>
                        <option value="" disabled selected>Select account type</option>
                        <option value="Basic">Basic — 5% loan interest</option>
                        <option value="Pioneer">Pioneer — 2% loan interest, +2% dividend share</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:#1a1a2e;">
                        <i class="fa-solid fa-plus me-2"></i>Open Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
