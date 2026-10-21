<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">Audit Logs</h4>
        <p class="text-muted mb-0 small">Who did what and when. Visible to the super administrator only; entries cannot be edited or deleted.</p>
    </div>
</div>

<form method="get" action="<?= base_url('admin/audit-logs') ?>" class="card mb-3" aria-label="Filter audit logs">
    <div class="card-body d-flex flex-wrap gap-3 align-items-end">
        <div>
            <label for="f_user" class="form-label small fw-semibold mb-1">User</label>
            <input type="text" id="f_user" name="user" class="form-control form-control-sm" value="<?= esc($filters['user']) ?>" placeholder="e.g. admin">
        </div>
        <div>
            <label for="f_action" class="form-label small fw-semibold mb-1">Action</label>
            <input type="text" id="f_action" name="action" class="form-control form-control-sm" value="<?= esc($filters['action']) ?>" placeholder="e.g. Approved, login">
        </div>
        <div>
            <label for="f_result" class="form-label small fw-semibold mb-1">Result</label>
            <select id="f_result" name="result" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="Success" <?= $filters['result'] === 'Success' ? 'selected' : '' ?>>Success</option>
                <option value="Failed" <?= $filters['result'] === 'Failed' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div>
            <label for="f_from" class="form-label small fw-semibold mb-1">From</label>
            <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($filters['from']) ?>">
        </div>
        <div>
            <label for="f_to" class="form-label small fw-semibold mb-1">To</label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($filters['to']) ?>">
        </div>
        <button class="btn btn-sm text-white" style="background:#1a1a2e;">Apply</button>
        <a href="<?= base_url('admin/audit-logs') ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:.875rem;">
            <thead class="table-light">
                <tr><th class="ps-3">Date and time</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th>Result</th><th class="pe-3">IP address</th></tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">No audit entries match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="ps-3 text-nowrap"><?= esc(date('M j, Y g:i:s A', strtotime($log['created_at']))) ?></td>
                        <td class="text-nowrap">
                            <?php if ($log['role']): ?><span class="small text-muted"><?= esc($log['role']) ?></span> <?php endif; ?>
                            <span class="fw-semibold"><?= esc($log['username'] ?? '—') ?></span>
                        </td>
                        <td><?= esc($log['action']) ?></td>
                        <td class="text-nowrap small"><?= $log['entity_type'] ? esc(str_replace('_', ' ', $log['entity_type'])) . ($log['entity_id'] !== null ? ' #' . esc($log['entity_id']) : '') : '—' ?></td>
                        <td class="small" style="max-width:360px;"><?= esc($log['details'] ?? '') ?></td>
                        <td><span class="badge <?= $log['result'] === 'Success' ? 'bg-success' : 'bg-danger' ?>"><?= esc($log['result']) ?></span></td>
                        <td class="pe-3 small text-muted"><?= esc($log['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= view('partials/pager', ['page' => $page, 'action' => 'admin/audit-logs', 'query' => $filters]) ?>
</div>

<?= $this->endSection() ?>
