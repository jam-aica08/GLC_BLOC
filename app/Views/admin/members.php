<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Members</h4>
        <p class="text-muted mb-0 small">Manage cooperative members and their accounts.</p>
    </div>
    <button type="button" class="btn btn-sm btn-brand"
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

<!-- Server-side search -->
<form method="get" action="<?= base_url('admin/members') ?>" class="mb-3" role="search">
    <div class="input-group">
        <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" name="q" class="form-control" value="<?= esc($search ?? '') ?>" aria-label="Search members"
               placeholder="Search by member ID, member #, name, account #, username or email">
        <button class="btn btn-brand" type="submit">Search</button>
        <?php if (! empty($search)): ?>
            <a href="<?= base_url('admin/members') ?>" class="btn btn-outline-secondary">Clear</a>
        <?php endif; ?>
    </div>
</form>

<!-- Announcement to every active member -->
<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#announceModal">
        <i class="fa-solid fa-bullhorn me-1"></i>Post Announcement
    </button>
</div>
<div class="modal fade" id="announceModal" tabindex="-1" aria-labelledby="announceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= base_url('admin/members/announce') ?>">
            <?= csrf_field() ?>
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="announceModalLabel"><i class="fa-solid fa-bullhorn me-2 glc-icon"></i>Post Announcement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-muted">Appears in the notifications of every active member.</p>
                <label for="ann_title" class="form-label fw-semibold small">Title</label>
                <input type="text" id="ann_title" name="title" class="form-control mb-3" maxlength="150" required>
                <label for="ann_message" class="form-label fw-semibold small">Message</label>
                <textarea id="ann_message" name="message" class="form-control" rows="4" maxlength="500" required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand"><i class="fa-solid fa-paper-plane me-1"></i>Send</button>
            </div>
        </form>
    </div>
</div>

<!-- Account requests from members -->
<?php if (! empty($accountRequests)): ?>
<div class="card mb-3 border-warning" id="account-requests">
    <div class="card-header d-flex justify-content-between align-items-center bg-warning-subtle">
        <span class="fw-semibold"><i class="fa-solid fa-inbox me-2"></i>Account Requests</span>
        <span class="badge bg-warning text-dark"><?= count($accountRequests) ?> waiting</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:0.875rem;">
            <thead class="table-light">
                <tr><th class="px-4">Member</th><th>Account Type</th><th>Requested</th><th class="text-end pe-4">Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($accountRequests as $req): ?>
                    <tr>
                        <td class="px-4">
                            <div class="fw-semibold"><?= esc($req['member_name']) ?></div>
                            <small class="text-muted"><?= esc($req['member_number']) ?></small>
                        </td>
                        <td><?= esc($req['account_type']) ?></td>
                        <td class="text-muted"><?= esc(date('M j, Y g:i A', strtotime($req['created_at']))) ?></td>
                        <td class="text-end pe-4">
                            <div class="d-inline-flex gap-1">
                                <form method="post" action="<?= base_url('admin/members/requests/' . (int) $req['request_id'] . '/approve') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Open a <?= esc($req['account_type'], 'js') ?> account for <?= esc($req['member_name'], 'js') ?>?')">
                                        <i class="fa-solid fa-check me-1"></i>Approve
                                    </button>
                                </form>
                                <form method="post" action="<?= base_url('admin/members/requests/' . (int) $req['request_id'] . '/reject') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?')">
                                        <i class="fa-solid fa-xmark me-1"></i>Reject
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">Approving opens the account. It becomes active once January to the month of approval is paid.</div>
</div>
<?php endif; ?>

<!-- Members Table Card -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-users me-2 glc-icon"></i>All Members</span>
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
                        <th class="py-3">Accounts</th>
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
                                         style="width:32px;height:32px;background:var(--glc-gradient-dark);color:#fff;font-weight:700;font-size:0.8rem;flex-shrink:0;">
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
                                <?php
                                    $accRows = array_map(fn($a) => [
                                        'number' => $a['account_number'],
                                        'type'   => $a['account_type'],
                                        'opened' => $a['account_start_date'],
                                        'status' => $a['account_status'] === 'Pending'
                                            ? 'Pending — ₱' . number_format($a['standing']['join_balance'], 2) . ' to activate'
                                            : $a['account_status'],
                                        'paid'   => '₱' . number_format($a['standing']['paid'], 2),
                                        'due'    => '₱' . number_format($a['standing']['due_balance'], 2),
                                    ], $member['accounts']);
                                    $pendingAcc = count(array_filter($member['accounts'], fn($a) => $a['account_status'] === 'Pending'));
                                ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#viewAccountsModal"
                                        data-member-name="<?= esc(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?>"
                                        data-accounts="<?= esc(json_encode($accRows)) ?>">
                                    <i class="fa-solid fa-wallet me-1"></i>View Accounts (<?= count($member['accounts']) ?>)
                                </button>
                                <?php if ($pendingAcc): ?>
                                    <span class="badge bg-warning text-dark ms-1"><?= $pendingAcc ?> pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center">
                                <?php if (($member['status'] ?? 'Active') === 'Active'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary mb-1"
                                            data-bs-toggle="modal" data-bs-target="#addAccountModal"
                                            data-member-id="<?= esc($member['id']) ?>"
                                            data-member-name="<?= esc(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?>">
                                        <i class="fa-solid fa-plus me-1"></i>Add Account
                                    </button>
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
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="addMemberModalLabel">
                    <i class="fa-solid fa-user-plus me-2 glc-icon"></i>Add New Member
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
                        <div class="col-md-6">
                            <label for="opening_contribution" class="form-label fw-semibold small">Opening Contribution (₱) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="opening_contribution" name="opening_contribution" min="300" max="3600" step="0.01" value="<?= \App\Models\CooperativeAccountModel::joinAmount() ?>" required>
                            <div class="form-text">₱<?= number_format(\App\Models\CooperativeAccountModel::joinAmount(), 2) ?> (January–<?= date('F') ?>) activates the account; it can be paid in parts of ₱300 to ₱3,600. Approved in Contributions.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-user-plus me-2"></i>Add Member
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1" aria-labelledby="addAccountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="addAccountModalLabel">
                    <i class="fa-solid fa-wallet me-2 glc-icon"></i>Add Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addAccountForm" method="post" action="">
                <?= csrf_field() ?>
                <div class="modal-body p-4">
                    <p class="mb-3">Add an account for <strong id="addAccountMemberName"></strong>.</p>
                    <label for="new_account_type" class="form-label fw-semibold small">Account Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="new_account_type" name="account_type" required>
                        <option value="" disabled selected>Select account type</option>
                        <option value="Pioneer">Pioneer</option>
                        <option value="Basic">Basic</option>
                    </select>
                    <div class="mt-3">
                        <label for="new_opening_contribution" class="form-label fw-semibold small">Opening Contribution (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="new_opening_contribution" name="opening_contribution" min="300" max="3600" step="0.01" value="<?= \App\Models\CooperativeAccountModel::joinAmount() ?>" required>
                        <div class="form-text">₱<?= number_format(\App\Models\CooperativeAccountModel::joinAmount(), 2) ?> (January–<?= date('F') ?>) activates the account; it can be paid in parts of ₱300 to ₱3,600. Approved in Contributions.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand">
                        <i class="fa-solid fa-plus me-2"></i>Open Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Accounts Modal -->
<div class="modal fade" id="viewAccountsModal" tabindex="-1" aria-labelledby="viewAccountsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title text-white" id="viewAccountsModalLabel">
                    <i class="fa-solid fa-wallet me-2 glc-icon"></i><span id="viewAccountsName"></span> — Accounts
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" style="font-size:0.875rem;">
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
                        <tbody id="viewAccountsBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('viewAccountsModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('viewAccountsName').textContent = btn.dataset.memberName;
    const body = document.getElementById('viewAccountsBody');
    body.replaceChildren();
    const rows = JSON.parse(btn.dataset.accounts || '[]');
    if (!rows.length) {
        const tr = body.insertRow(), td = tr.insertCell();
        td.colSpan = 6; td.className = 'text-center text-muted py-4'; td.textContent = 'No accounts yet.';
        return;
    }
    rows.forEach(function (r) {
        const tr = body.insertRow();
        [r.number, r.type, r.opened, r.status, r.paid, r.due].forEach(function (v, i) {
            const td = tr.insertCell();
            td.className = i === 0 ? 'px-4 py-3' : 'py-3';
            td.textContent = v;
        });
    });
});
document.getElementById('addAccountModal').addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('addAccountForm').action =
        <?= json_encode(rtrim(base_url('admin/members'), '/') . '/') ?> + encodeURIComponent(btn.dataset.memberId) + '/accounts/add';
    document.getElementById('addAccountMemberName').textContent = btn.dataset.memberName;
});
</script>

<?= $this->endSection() ?>
