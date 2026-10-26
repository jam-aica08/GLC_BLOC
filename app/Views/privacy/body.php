<?php
/**
 * The privacy and data retention notice, from Config\Retention, so it always
 * matches what the retention review does.
 *
 * @var list<array> $schedule
 * @var \Config\Retention $config
 */
?>
<div class="mb-4">
    <h1 class="h4 fw-bold mb-1"><i class="fa-solid fa-user-shield me-2 glc-icon"></i>Privacy &amp; Data Retention</h1>
    <p class="text-muted small mb-0">How GLC-BLOC keeps members' information: what is kept, why, for how long, and what happens after.</p>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <p class="mb-2">The cooperative keeps personal information only for the purposes below and no longer than needed,
            in line with the Data Privacy Act of 2012 (Republic Act No. 10173) and the rules of the National Privacy Commission.
            Financial records are kept longer than personal details because the books of account must be kept for audit.</p>
        <p class="mb-0 small text-muted">Deactivating an account or a membership is not the same as deleting data: the records of a deactivated account are kept for the period below.</p>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-table-list me-2"></i>Retention schedule</div>
    <!-- Wider than a phone: the box scrolls sideways, so it takes keyboard focus. -->
    <div class="table-responsive" tabindex="0" role="region" aria-label="Retention schedule (scrolls sideways)">
        <table class="table align-middle mb-0" style="font-size:0.85rem; min-width: 900px;">
            <thead class="table-light">
                <tr><th class="px-3">Information</th><th>Stored in</th><th>Why it is kept</th><th>How long</th><th>Then</th></tr>
            </thead>
            <tbody>
                <?php foreach ($schedule as $row): ?>
                    <tr>
                        <td class="px-3"><div class="fw-semibold"><?= esc($row['category']) ?></div><small class="text-muted"><?= esc($row['data']) ?></small></td>
                        <td><small><?= esc($row['stored']) ?></small></td>
                        <td><?= esc($row['purpose']) ?></td>
                        <td><?= esc($row['period']) ?></td>
                        <td><?= esc($row['action']) ?><br><span class="badge bg-light text-dark border"><?= esc($row['mode']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-hourglass-half me-2"></i>Inactive memberships</div>
            <div class="card-body small">
                <p>Activity means signing in, or a contribution or loan payment recorded for one of your accounts.</p>
                <ol class="mb-0">
                    <li class="mb-1"><strong><?= (int) $config->inactiveFlagMonths ?> months</strong> without activity: the membership is flagged for review and you receive a notification.</li>
                    <li class="mb-1"><strong><?= (int) $config->inactiveDeactivateMonths ?> months</strong>: the membership and sign-in are deactivated. This is skipped while you have an open loan or a payment waiting for approval. Your accounts and records are kept, and the office can reactivate you.</li>
                    <li class="mb-1"><strong><?= (int) $config->inactiveLoginDataMonths ?> months</strong>: the super administrator may remove your username and email address.</li>
                    <li><strong><?= (int) $config->financialYears ?> years</strong> after the year of your last transaction: your name may be replaced with "Former member". Member and account numbers stay so the cooperative's books still add up.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-scale-balanced me-2"></i>Your rights and exceptions</div>
            <div class="card-body small">
                <p>You can ask the cooperative office to see, correct or delete your information. Some information cannot be deleted
                    before the end of its period because the law or the cooperative's audit requires it; the office will explain when that applies.</p>
                <p>You can deactivate one of your accounts from <strong>My Accounts</strong>. An administrator reviews the request; the account's records are kept.</p>
                <p class="mb-0"><strong>Legal hold:</strong> when information is needed for a dispute, complaint, audit or investigation, its scheduled
                    deactivation, removal and anonymization stop until the matter is closed.</p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold bg-white"><i class="fa-solid fa-lock me-2"></i>How retained information is protected</div>
    <div class="card-body small">
        <ul class="mb-0">
            <li>Role-based access: members see only their own accounts; only administrators see other members, and only the super administrator sees audit logs and runs retention actions.</li>
            <li>Passwords are stored as one-way hashes; sign-ins are rate-limited and end after 20 minutes without activity.</li>
            <li>Every connection uses HTTPS, and the database connection is encrypted.</li>
            <li>Approvals and changes are recorded in the audit trail; financial records are chained in a tamper-evident ledger.</li>
            <li>Data is deleted from the live database by the daily retention review; deleted data leaves the provider's backups when those backups expire.</li>
        </ul>
    </div>
</div>
