<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?= view('partials/page_title', ['suffix' => 'GLC-BLOC Member Portal']) ?>
    <?= view('partials/app_head') ?>
    <?php if (preg_match('#/(dashboard|analytics)$#', '/' . uri_string())): ?>
    <!-- Chart.js (only the pages with charts need it); deferred so it does not hold up the first paint -->
    <script src="<?= base_url('assets/lib/chart.js-4.4.1/chart.umd.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body>
<div class="glc-topline" aria-hidden="true"></div>

<?php
$glcNav = [
    'Main' => [
        ['member/dashboard', 'fa-gauge-high', 'Dashboard', true],
    ],
    'Financial' => [
        ['member/contributions', 'fa-piggy-bank', 'My Contributions'],
        ['member/loans', 'fa-hand-holding-dollar', 'My Loans'],
        ['member/payments', 'fa-money-check-dollar', 'My Payments'],
        ['member/dividends', 'fa-percent', 'My Dividends'],
    ],
    'Ledger & Reports' => [
        ['member/ledger', 'fa-link', 'Blockchain Ledger'],
        ['member/analytics', 'fa-chart-line', 'Analytics'],
    ],
];
?>
<!-- Sidebar -->
<aside id="sidebar" aria-label="Main navigation">
    <div class="d-flex align-items-center">
        <a class="sidebar-brand" href="<?= base_url('member/dashboard') ?>">
            <img src="<?= base_url('assets/img/logo-sm.webp') ?>" alt="" width="64" height="25">
            <span>
                <span class="brand-name d-block">GLC-BLOC</span>
                <span class="brand-sub">Member Portal</span>
            </span>
        </a>
        <button type="button" class="icon-btn sidebar-close d-lg-none me-2" data-sidebar-close aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <nav class="flex-grow-1">
        <?= view('partials/sidebar_nav', ['groups' => $glcNav]) ?>
    </nav>

    <div class="sidebar-footer">GLC-BLOC &copy; <?= date('Y') ?></div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>

<!-- Main Content -->
<div id="main-content">
    <!-- Top Navbar -->
    <header id="top-navbar">
        <div class="topbar-left">
            <button type="button" class="icon-btn d-lg-none" id="sidebar-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
                <i class="fa-solid fa-bars fa-lg"></i>
            </button>
            <img src="<?= base_url('assets/img/logo-sm.webp') ?>" alt="GLC-BLOC" class="topbar-logo d-lg-none" width="72" height="28">
            <div class="navbar-title">Guyo &amp; Liding Cooperative</div>
        </div>
        <div class="user-info">
            <?php
                $glcMember = (int) session()->get('member_id');
                $glcUnread = \App\Services\Notifier::unreadCount($glcMember);
                $glcLatest = \App\Services\Notifier::latest($glcMember, 6);
                $glcHere   = uri_string();
            ?>
            <div class="dropdown">
                <button type="button" class="notif-toggle position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                        aria-label="Notifications<?= $glcUnread ? ', ' . $glcUnread . ' unread' : '' ?>">
                    <i class="fa-solid fa-bell fa-lg"></i>
                    <?php if ($glcUnread): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem;"><?= $glcUnread ?></span><?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow notif-menu p-0">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <span class="fw-semibold">Notifications<?= $glcUnread ? ' <span class="badge bg-danger ms-1">' . $glcUnread . '</span>' : '' ?></span>
                        <?php if ($glcUnread): ?>
                            <form method="post" action="<?= base_url('member/notifications/read-all') ?>">
                                <?= csrf_field() ?><input type="hidden" name="next" value="<?= esc($glcHere) ?>">
                                <button class="btn btn-link btn-sm p-0">Mark all as read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="notif-scroll">
                        <?= view('partials/notification_list', ['notifications' => $glcLatest, 'next' => $glcHere]) ?>
                    </div>
                    <a class="d-block text-center small py-2 border-top" href="<?= base_url('member/notifications') ?>">View all notifications</a>
                </div>
            </div>
            <div class="dropdown">
                <button class="profile-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open profile menu">
                    <span class="user-avatar"><?= esc(mb_strtoupper(mb_substr(session()->get('username') ?? 'M', 0, 1))) ?></span>
                    <span class="user-name d-none d-sm-inline">
                        <strong><?= esc(session()->get('username') ?? 'Member') ?></strong>
                        <br><small class="text-muted">Member Account</small>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end profile-menu shadow-sm">
                    <li class="d-sm-none px-3 py-2 small text-muted border-bottom mb-1">
                        Signed in as <strong class="text-body"><?= esc(session()->get('username') ?? 'Member') ?></strong>
                    </li>
                    <li><a class="dropdown-item" href="<?= base_url('member/profile') ?>"><i class="fa-solid fa-circle-user"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('member/settings') ?>"><i class="fa-solid fa-gear"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form action="<?= base_url('logout') ?>" method="post">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item"><i class="fa-solid fa-right-from-bracket"></i>Logout</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Page Content -->
    <main class="content-area">
        <?= $this->renderSection('content') ?>
    </main>
</div>

<!-- Bootstrap 5 JS bundle -->
<script src="<?= base_url('assets/lib/bootstrap-5.3.2/bootstrap.bundle.min.js') ?>"></script>
<?= view('partials/table_search') ?>
<?= view('partials/app_shell_js') ?>

</body>
</html>
