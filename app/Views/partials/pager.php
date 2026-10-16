<?php
/**
 * Page links under a list. Keeps the status filter and search text.
 *
 * @var array  $page   ['page' => int, 'pages' => int, 'total' => int, 'from' => int, 'to' => int]
 * @var string $action Page URL (e.g. 'admin/loans')
 * @var array  $query  Extra query values to keep (status, q)
 */
$link = fn(int $n) => base_url($action) . '?' . http_build_query(array_filter($query + ['page' => $n], fn($v) => $v !== '' && $v !== null));
$current = $page['page'];
$last    = $page['pages'];
$numbers = array_unique(array_filter([1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $last], fn($n) => $n >= 1 && $n <= $last));
sort($numbers);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">
    <span class="small text-muted">
        <?= $page['total'] ? "Showing {$page['from']}–{$page['to']} of {$page['total']}" : 'No records' ?>
    </span>
    <?php if ($last > 1): ?>
        <nav aria-label="Pages">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $current <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link(max(1, $current - 1)) ?>">Previous</a></li>
                <?php $prev = 0; foreach ($numbers as $n): ?>
                    <?php if ($n > $prev + 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item <?= $n === $current ? 'active' : '' ?>" <?= $n === $current ? 'aria-current="page"' : '' ?>><a class="page-link" href="<?= $link($n) ?>"><?= $n ?></a></li>
                <?php $prev = $n; endforeach; ?>
                <li class="page-item <?= $current >= $last ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link(min($last, $current + 1)) ?>">Next</a></li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
