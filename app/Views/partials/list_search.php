<?php
/**
 * Server-side search box for a paged list. Keeps the current status filter.
 *
 * @var string $action       Page URL (e.g. 'admin/loans')
 * @var string $search       Current search text
 * @var string $filterStatus Current status filter
 * @var string $placeholder
 */
?>
<form method="get" action="<?= base_url($action) ?>" class="mb-3" role="search">
    <?php if (($filterStatus ?? '') !== ''): ?><input type="hidden" name="status" value="<?= esc($filterStatus) ?>"><?php endif; ?>
    <div class="input-group">
        <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="search" name="q" class="form-control" value="<?= esc($search ?? '') ?>" aria-label="Search"
               placeholder="<?= esc($placeholder) ?>">
        <button class="btn text-white" style="background:#1a1a2e;" type="submit">Search</button>
        <?php if (($search ?? '') !== ''): ?>
            <a class="btn btn-outline-secondary" href="<?= base_url($action . (($filterStatus ?? '') !== '' ? '?status=' . urlencode($filterStatus) : '')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>
