<?php
/**
 * Explains the activation rule with this month as the example.
 *
 * @var string $class Extra classes for the box (optional)
 */
use App\Models\CooperativeAccountModel as Acc;

$join  = Acc::joinAmount();
$month = date('F');
$count = (int) date('n');
?>
<div class="alert alert-info small py-2 <?= esc($class ?? 'mb-3') ?>" role="note">
    <i class="fa-solid fa-circle-info me-1"></i><strong>Business rule:</strong>
    a new account must be paid from January up to the month it is opened before it becomes active.
    <div class="mt-1"><strong>Example:</strong> it is <?= esc($month) ?>, so a new account needs at least
        <strong>₱<?= number_format($join, 2) ?></strong> (January–<?= esc($month) ?>, <?= $count ?> × ₱<?= number_format(Acc::MONTHLY_CONTRIBUTION, 0) ?>),
        up to ₱<?= number_format(Acc::CONTRIBUTION_MAX, 0) ?>, to be activated.</div>
    <div class="mt-1">Once the account is <strong>active</strong>, each contribution can be ₱<?= number_format(Acc::CONTRIBUTION_MIN, 0) ?> to ₱<?= number_format(Acc::CONTRIBUTION_MAX, 0) ?>.</div>
</div>
