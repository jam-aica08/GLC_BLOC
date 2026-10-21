<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLC-BLOC Admin Panel</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CDN -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --sidebar-bg: #1a1a2e;
            --sidebar-text: #c9d1d9;
            --sidebar-active-bg: #f0a500;
            --sidebar-active-text: #1a1a2e;
            --sidebar-hover-bg: rgba(240, 165, 0, 0.15);
            --accent-color: #f0a500;
            --navbar-bg: #ffffff;
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Sidebar */
        #sidebar {
            width: 260px;
            min-height: 100vh;
            background-color: var(--sidebar-bg);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        #sidebar .sidebar-brand {
            padding: 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }

        #sidebar .sidebar-brand h4 {
            color: var(--accent-color);
            font-weight: 700;
            margin: 0;
            font-size: 1.4rem;
            letter-spacing: 1px;
        }

        #sidebar .sidebar-brand p {
            color: var(--sidebar-text);
            font-size: 0.75rem;
            margin: 4px 0 0;
            opacity: 0.7;
        }

        #sidebar .nav-link {
            color: var(--sidebar-text);
            padding: 12px 20px;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        #sidebar .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 1rem;
        }

        #sidebar .nav-link:hover {
            background-color: var(--sidebar-hover-bg);
            color: var(--accent-color);
            padding-left: 28px;
        }

        #sidebar .nav-link.active {
            background-color: var(--accent-color);
            color: var(--sidebar-active-text);
            font-weight: 600;
        }

        #sidebar .nav-link.active i {
            color: var(--sidebar-active-text);
        }

        #sidebar .nav-section-title {
            color: rgba(255,255,255,0.3);
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            padding: 16px 20px 6px;
        }

        /* Main content */
        #main-content {
            margin-left: 260px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top navbar */
        #top-navbar {
            background-color: var(--navbar-bg);
            border-bottom: 1px solid #e0e0e0;
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }

        #top-navbar .navbar-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        #top-navbar .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        #top-navbar .user-info .user-name {
            font-size: 0.9rem;
            color: #555;
        }

        #top-navbar .user-info .user-avatar {
            width: 36px;
            height: 36px;
            background-color: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1a1a2e;
            font-weight: 700;
            font-size: 0.85rem;
        }

        /* Readable, near-black text everywhere. Colour stays on badges, alerts,
           buttons, icons and validation messages, which carry status. */
        body, h1, h2, h3, h4, h5, h6, .form-label, .card, .modal-content, .dropdown-menu { color: #16161d; }
        .table { --bs-table-color: #16161d; --bs-table-hover-color: #16161d; }
        code { color: #16161d; }
        :is(.text-success, .text-danger, .text-primary, .text-info, .text-warning):not(i):not(.badge):not(.invalid-feedback) { color: #16161d !important; }
        /* Notifications dropdown (bell) */
        .notif-toggle { background: none; border: 0; color: #1a1a2e; padding: 6px 8px; border-radius: 8px; }
        .notif-toggle:hover, .notif-toggle:focus-visible { background: rgba(26,26,46,0.06); }
        .notif-menu { width: min(380px, calc(100vw - 24px)); }
        .notif-menu .notif-scroll { max-height: 360px; overflow-y: auto; }
        .notif-unread { background: #fffaf0; }
        @media (max-width: 575.98px) {
            .notif-menu.show { position: fixed !important; inset: 64px 12px auto 12px !important; transform: none !important; width: auto; }
        }
        /* Profile dropdown */
        .profile-toggle { background: none; border: 0; display: flex; align-items: center; gap: 10px; padding: 4px 6px; border-radius: 8px; color: inherit; text-align: left; }
        .profile-toggle:hover, .profile-toggle:focus-visible { background: rgba(26,26,46,0.06); }
        .profile-menu .dropdown-item { display: flex; align-items: center; gap: 10px; padding: 8px 16px; }
        .profile-menu .dropdown-item i { width: 16px; text-align: center; color: #1a1a2e; }
        .profile-menu button.dropdown-item { color: #b02a37; }
        .profile-menu button.dropdown-item i { color: #b02a37; }
        .btn-logout {
            background-color: transparent;
            border: 1px solid #dc3545;
            color: #dc3545;
            font-size: 0.8rem;
            padding: 5px 14px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .btn-logout:hover {
            background-color: #dc3545;
            color: #fff;
        }

        /* Content area */
        .content-area {
            flex: 1;
            padding: 24px;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e9ecef;
            border-radius: 12px 12px 0 0 !important;
            padding: 14px 20px;
            font-weight: 600;
            color: #333;
        }

        @media (max-width: 768px) {
            #sidebar {
                width: 0;
                overflow: hidden;
            }
            #main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div id="sidebar">
    <div class="sidebar-brand">
        <h4><i class="fa-solid fa-cube me-1"></i> GLC-BLOC</h4>
        <p>Admin Panel</p>
    </div>

    <nav class="mt-2 flex-grow-1">
        <div class="nav-section-title">Main</div>

        <a href="<?= base_url('admin/dashboard') ?>" class="nav-link <?= (uri_string() === 'admin/dashboard') ? 'active' : '' ?>">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>

        <a href="<?= base_url('admin/members') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/members') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-users"></i> Members
        </a>

        <div class="nav-section-title">Financial</div>

        <a href="<?= base_url('admin/contributions') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/contributions') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-piggy-bank"></i> Contributions
        </a>

        <a href="<?= base_url('admin/loans') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/loans') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-hand-holding-dollar"></i> Loans
        </a>

        <a href="<?= base_url('admin/payments') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/payments') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-money-bill-wave"></i> Payments
        </a>

        <a href="<?= base_url('admin/financial-records') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/financial-records') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i> Financial Records
        </a>

        <a href="<?= base_url('admin/dividends') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/dividends') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-percent"></i> Dividends
        </a>

        <div class="nav-section-title">Reports & Ledger</div>

        <a href="<?= base_url('admin/ledger') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/ledger') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-link"></i> Blockchain Ledger
        </a>

        <a href="<?= base_url('admin/analytics') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/analytics') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i> Analytics
        </a>

        <a href="<?= base_url('admin/reports') ?>" class="nav-link <?= (strpos(uri_string(), 'admin/reports') === 0) ? 'active' : '' ?>">
            <i class="fa-solid fa-file-lines"></i> Reports
        </a>

    </nav>

    <div class="p-3 border-top" style="border-color: rgba(255,255,255,0.1) !important;">
        <p class="text-center mb-0" style="color: rgba(255,255,255,0.3); font-size: 0.7rem;">
            GLC-BLOC &copy; <?= date('Y') ?>
        </p>
    </div>
</div>

<!-- Main Content -->
<div id="main-content">
    <!-- Top Navbar -->
    <div id="top-navbar">
        <div class="navbar-title">
            <i class="fa-solid fa-bars me-2 d-md-none" id="sidebar-toggle" style="cursor:pointer;"></i>
            Guyo &amp; Liding Cooperative
        </div>
        <div class="user-info">
            <?php
                $glcAdmin  = (int) session()->get('user_id');
                $glcUnread = \App\Services\Notifier::adminUnreadCount($glcAdmin);
                $glcLatest = \App\Services\Notifier::adminLatest($glcAdmin, 6);
                $glcHere   = uri_string();
            ?>
            <div class="dropdown me-2">
                <button type="button" class="notif-toggle position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                        aria-label="Notifications<?= $glcUnread ? ', ' . $glcUnread . ' unread' : '' ?>">
                    <i class="fa-solid fa-bell fa-lg"></i>
                    <?php if ($glcUnread): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem;"><?= $glcUnread ?></span><?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow notif-menu p-0">
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <span class="fw-semibold">Notifications<?= $glcUnread ? ' <span class="badge bg-danger ms-1">' . $glcUnread . '</span>' : '' ?></span>
                        <?php if ($glcUnread): ?>
                            <form method="post" action="<?= base_url('admin/notifications/read-all') ?>">
                                <?= csrf_field() ?><input type="hidden" name="next" value="<?= esc($glcHere) ?>">
                                <button class="btn btn-link btn-sm p-0">Mark all as read</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="notif-scroll">
                        <?= view('partials/notification_list', ['notifications' => $glcLatest, 'next' => $glcHere, 'area' => 'admin']) ?>
                    </div>
                    <a class="d-block text-center small py-2 border-top" href="<?= base_url('admin/notifications') ?>">View all notifications</a>
                </div>
            </div>
            <div class="dropdown">
                <button class="profile-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open profile menu">
                    <span class="user-avatar"><?= strtoupper(substr(session()->get('username') ?? 'A', 0, 1)) ?></span>
                    <span class="user-name">
                        <strong><?= esc(session()->get('username') ?? 'Admin') ?></strong>
                        <br><small class="text-muted"><?= session()->get('is_super_admin') ? 'Super Admin' : 'Admin' ?></small>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end profile-menu shadow-sm">
                    <li><a class="dropdown-item" href="<?= base_url('admin/settings') ?>"><i class="fa-solid fa-gear"></i>Settings</a></li>
                    <?php if (session()->get('is_super_admin')): ?>
                        <li><a class="dropdown-item" href="<?= base_url('admin/audit-logs') ?>"><i class="fa-solid fa-clipboard-list"></i>Audit Logs</a></li>
                    <?php endif; ?>
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
    </div>

    <!-- Page Content -->
    <div class="content-area">
        <?= $this->renderSection('content') ?>
    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?= view('partials/table_search') ?>
<script>
    // Sidebar toggle for mobile
    const sidebarToggle = document.getElementById('sidebar-toggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            if (sidebar.style.width === '260px' || sidebar.style.width === '') {
                sidebar.style.width = '0';
                mainContent.style.marginLeft = '0';
            } else {
                sidebar.style.width = '260px';
                mainContent.style.marginLeft = '260px';
            }
        });
    }
</script>

</body>
</html>
