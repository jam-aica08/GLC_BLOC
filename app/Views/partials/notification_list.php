<?php
/**
 * A member's or an administrator's notifications. Unread ones are marked and can be marked read.
 *
 * @var array  $notifications
 * @var string $next  The page to come back to after marking read
 * @var string $area  'member' (default) or 'admin'
 */
$area  = ($area ?? 'member') === 'admin' ? 'admin' : 'member';
$icons = ['contribution' => 'fa-piggy-bank', 'payment' => 'fa-money-bill-wave', 'loan' => 'fa-hand-holding-dollar',
          'dividend' => 'fa-coins', 'account' => 'fa-wallet', 'announcement' => 'fa-bullhorn', 'ledger' => 'fa-shield-halved'];
?>
<?php if (empty($notifications)): ?>
    <div class="text-center text-muted small py-4">
        <i class="fa-regular fa-bell fa-lg d-block mb-2"></i>
        <?= $area === 'admin'
            ? 'No notifications yet. New contributions, loan applications, payments and account requests to review will appear here.'
            : 'No notifications yet. Updates about your contributions, payments, loans and dividends will appear here.' ?>
    </div>
<?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($notifications as $n):
            $unread    = ! (int) $n['is_read'];
            $important = $unread && \App\Services\Notifier::isImportant($area, $n['event_key']);   // stays unread until dealt with
        ?>
            <li class="list-group-item d-flex gap-3 align-items-start py-3 <?= $unread ? 'notif-unread' : '' ?>">
                <i class="fa-solid <?= $icons[$n['type']] ?? 'fa-bell' ?> mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <span class="<?= $unread ? 'fw-bold' : 'fw-semibold' ?>">
                            <?= esc($n['title']) ?>
                            <?php if ($important): ?><span class="badge bg-danger ms-1">Action needed</span>
                            <?php elseif ($unread): ?><span class="badge bg-warning text-dark ms-1">New</span><?php endif; ?>
                        </span>
                        <time class="small text-muted text-nowrap" datetime="<?= esc($n['created_at']) ?>"><?= esc(date('M j, Y g:i A', strtotime($n['created_at']))) ?></time>
                    </div>
                    <div class="small"><?= esc($n['message']) ?></div>
                    <div class="d-flex gap-3 mt-1">
                        <?php if (! empty($n['link'])): ?><a class="small" href="<?= base_url($n['link']) ?>"><?= str_starts_with((string) $n['event_key'], 'activate-') ? 'Contribute now' : 'Open' ?></a><?php endif; ?>
                        <?php if ($unread): ?>
                            <form method="post" action="<?= base_url($area . '/notifications/' . (int) $n['notification_id'] . '/read') ?>">
                                <?= csrf_field() ?><input type="hidden" name="next" value="<?= esc($next) ?>">
                                <button class="btn btn-link btn-sm p-0 small">Mark as read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
