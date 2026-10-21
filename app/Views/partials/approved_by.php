<?php
/**
 * Who approved a record and when.
 *
 * @var ?string $name
 * @var ?string $at
 */
?>
<?php if (! empty($name)): ?>
    <div class="small">by <?= esc($name) ?></div>
    <?php if (! empty($at)): ?><div class="small text-muted"><?= esc(date('M j, Y g:i A', strtotime($at))) ?></div><?php endif; ?>
<?php endif; ?>
