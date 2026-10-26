<?= $this->extend('layouts/member_main') ?>
<?= $this->section('content') ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 fw-bold mb-1">Notifications</h1>
        <p class="text-muted small mb-0"><?= (int) $unread ?> unread</p>
    </div>
    <?php if ($unread): ?>
        <form method="post" action="<?= base_url('member/notifications/read-all') ?>">
            <?= csrf_field() ?><input type="hidden" name="next" value="member/notifications">
            <button class="btn btn-sm btn-outline-secondary">Mark all as read</button>
        </form>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <?= view('partials/notification_list', ['notifications' => $notifications, 'next' => 'member/notifications']) ?>
</div>

<?= $this->endSection() ?>
