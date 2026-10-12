<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="fas fa-user-circle me-2 text-warning"></i>My Profile</h4>
</div>

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

<?= $this->endSection() ?>
