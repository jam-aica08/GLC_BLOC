<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="mb-4">
    <h4 class="fw-bold mb-1"><i class="fa-solid fa-money-check-dollar me-2 text-warning"></i>My Payments</h4>
    <p class="text-muted small mb-0">Loan payments you submitted and their approval status.</p>
</div>

<?= view('partials/flash') ?>

<div class="card shadow-sm" data-search>
    <div class="card-header d-flex justify-content-between align-items-center bg-white">
        <span class="fw-semibold">Payment History</span>
        <span class="badge bg-secondary"><?= count($payments) ?> record(s)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.875rem;">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th class="px-4 py-3">Payment ID</th>
                        <th class="py-3">Loan</th>
                        <th class="py-3">Account</th>
                        <th class="py-3">Amount</th>
                        <th class="py-3">Date</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Loan Paid / Total</th>
                        <th class="py-3">Loan Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">No payments yet. Pay from <a href="<?= base_url('member/loans') ?>">My Loans</a> → View &amp; Pay.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($payments as $p): ?>
                        <?php
                            $label = ['Approved' => 'Paid', 'Pending' => 'Pending Approval', 'Rejected' => 'Rejected'][$p['status']] ?? $p['status'];
                            $badge = ['Approved' => 'bg-success', 'Pending' => 'bg-warning text-dark', 'Rejected' => 'bg-danger'][$p['status']] ?? 'bg-secondary';
                            $balance = max(0, (float) $p['total_payable'] - (float) $p['total_paid']);
                        ?>
                        <tr>
                            <td class="px-4 py-3"><code>#<?= esc($p['id']) ?></code></td>
                            <td class="py-3"><a href="<?= base_url('member/loans/' . $p['loan_id'] . '/installments') ?>">Loan #<?= esc($p['loan_id']) ?></a></td>
                            <td class="py-3"><code><?= esc($p['account_number']) ?></code></td>
                            <td class="py-3 fw-semibold">₱<?= number_format($p['amount_paid'], 2) ?></td>
                            <td class="py-3 text-muted"><?= esc($p['payment_date']) ?></td>
                            <td class="py-3"><span class="badge <?= $badge ?>"><?= esc($label) ?></span></td>
                            <td class="py-3">₱<?= number_format($p['total_paid'], 2) ?> / ₱<?= number_format($p['total_payable'], 2) ?></td>
                            <td class="py-3 <?= $balance > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">₱<?= number_format($balance, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
