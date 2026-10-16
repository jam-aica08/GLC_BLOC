<?php
/**
 * One-line hint about activating a new account, with this month's amount.
 * Rule: PHP 300 for every month from January to the opening month, paid in
 * contributions of up to PHP 3,600; after that PHP 300 to 3,600 each.
 *
 * @var string $class Extra classes (optional)
 */
use App\Models\CooperativeAccountModel as Acc;
?>
<p class="small text-muted <?= esc($class ?? 'mb-3') ?>">
    A new account is activated once January to <?= esc(date('F')) ?> is paid
    (₱<?= number_format(Acc::joinAmount(), 2) ?> this month).
</p>
