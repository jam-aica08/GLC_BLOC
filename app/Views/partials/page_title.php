<?php
/**
 * <title> for a signed-in page: "Contributions · GLC-BLOC Admin Panel", named
 * after the page from its address, so each browser tab (and screen reader)
 * says which page it is. $suffix is the portal name.
 */
$segments = explode('/', uri_string());
$names    = [
    'dashboard'         => 'Dashboard',
    'members'           => 'Members',
    'contributions'     => $segments[0] === 'member' ? 'My Contributions' : 'Contributions',
    'loans'             => isset($segments[2]) ? 'Loan schedule' : ($segments[0] === 'member' ? 'My Loans' : 'Loans'),
    'payments'          => $segments[0] === 'member' ? 'My Payments' : 'Payments',
    'financial-records' => 'Financial Records',
    'dividends'         => $segments[0] === 'member' ? 'My Dividends' : 'Dividends',
    'ledger'            => $segments[0] === 'member' ? 'My Ledger' : 'Blockchain Ledger',
    'notifications'     => 'Notifications',
    'audit-logs'        => 'Audit Logs',
    'analytics'         => 'Analytics',
    'reports'           => 'Reports',
    'import'            => 'Import data',
    'settings'          => 'Settings',
    'profile'           => 'My Profile',
];
$page = $names[$segments[1] ?? ''] ?? null;
?>
    <title><?= esc(($page ? $page . ' · ' : '') . $suffix) ?></title>
