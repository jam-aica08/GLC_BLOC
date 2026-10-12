<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Members</h4>
        <p class="text-muted mb-0 small">Manage cooperative members and their accounts.</p>
    </div>
    <button type="button" class="btn btn-sm text-white" style="background:#1a1a2e;"
            data-bs-toggle="modal" data-bs-target="#addMemberModal">
        <i class="fa-solid fa-user-plus me-2"></i>Add New Member
    </button>
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
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= esc(session()->getFlashdata('error')) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Members Table Card -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-users me-2" style="color:#f0a500;"></i>All Members</span>
        <span class="badge bg-secondary"><?= count($members ?? []) ?> total</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Member #</th>
                        <th class="py-3">Name</th>
                        <th class="py-3">Email</th>
                        <th class="py-3">Membership Date</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Account Type</th>
                        <th class="py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($members)): ?>
                        <?php foreach ($members as $member): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <code>#<?= esc($member['member_number'] ?? '-') ?></code>
                            </td>
                            <td class="py-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                                         style="width:32px;height:32px;background:#1a1a2e;color:#f0a500;font-weight:700;font-size:0.8rem;flex-shrink:0;">
                                        <?= strtoupper(substr($member['first_name'] ?? 'M', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?= esc(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?></div>
                                        <small class="text-muted">@<?= esc($member['username'] ?? '') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3"><?= esc($member['email'] ?? '-') ?></td>
                            <td class="py-3 text-muted"><?= esc($member['membership_date'] ?? $member['created_at'] ?? '-') ?></td>
                            <td class="py-3">
                                <?php $status = $member['status'] ?? $member['membership_status'] ?? 'Active'; ?>
                                <span class="badge <?= ($status === 'Active') ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= esc($status) ?>
                                </span>
                            </td>
                            <td class="py-3">
                                <?php $type = $member['account_type'] ?? 'Basic'; ?>
                                <span class="badge <?= ($type === 'Pioneer') ? 'text-white' : 'bg-light text-dark border' ?>"
                                      <?= ($type === 'Pioneer') ? 'style="background:#1a1a2e;"' : '' ?>>
                                    <?= esc($type) ?>
                                </span>
                            </td>
                            <td class="py-3 text-center">
                                <?php if (($member['status'] ?? 'Active') === 'Active'): ?>
                                    <form action="<?= base_url('admin/members/deactivate/' . $member['id']) ?>" method="post"
                                          onsubmit="return confirm('Deactivate member ' + <?= json_encode(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?> + '?')">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                            <i class="fa-solid fa-user-slash me-1"></i>Deactivate
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form action="<?= base_url('admin/members/reactivate/' . $member['id']) ?>" method="post"
                                          onsubmit="return confirm('Reactivate member ' + <?= json_encode(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?> + '?')">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Reactivate">
                                            <i class="fa-solid fa-user-check me-1"></i>Reactivate
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-users fa-2x mb-3 d-block opacity-25"></i>
                                No members found. Click "Add New Member" to get started.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1" aria-labelledby="addMemberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a1a2e;">
                <h5 class="modal-title text-white" id="addMemberModalLabel">
                    <i class="fa-solid fa-user-plus me-2" style="color:#f0a500;"></i>Add New Member
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= base_url('admin/members/add') ?>" method="post">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="first_name" class="form-label fw-semibold small">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                   placeholder="e.g. Juan" required value="<?= esc(old('first_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="last_name" class="form-label fw-semibold small">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                   placeholder="e.g. dela Cruz" required value="<?= esc(old('last_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold small">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   placeholder="juan@example.com" required value="<?= esc(old('email')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="username" class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-at"></i></span>
                                <input type="text" class="form-control" id="username" name="username"
                                       placeholder="juandelacruz" required value="<?= esc(old('username')) ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="modal_password" name="password"
                                   placeholder="Minimum 8 characters" minlength="8" required>
                        </div>
                        <div class="col-md-6">
                            <label for="account_type" class="form-label fw-semibold small">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="account_type" name="account_type" required>
                                <option value="" disabled selected>Select account type</option>
                                <option value="Pioneer" <?= (old('account_type') === 'Pioneer') ? 'selected' : '' ?>>Pioneer</option>
                                <option value="Basic" <?= (old('account_type') === 'Basic') ? 'selected' : '' ?>>Basic</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:#1a1a2e;">
                        <i class="fa-solid fa-user-plus me-2"></i>Add Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
