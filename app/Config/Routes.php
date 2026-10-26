<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// Auth routes
$routes->get('/', 'Auth::index');
$routes->get('/login', 'Auth::index');
$routes->post('/login', 'Auth::login');
$routes->post('/logout', 'Auth::logout');   // FIX 4.2: POST-only logout

// Data retention: public privacy notice, and the daily review (Vercel Cron, secret-protected)
$routes->get('privacy', 'Retention::notice');
$routes->get('cron/retention', 'Retention::cron');

// Admin routes (protected by auth + admin filter)
$routes->group('admin', ['filter' => 'auth:admin'], function ($routes) {
    $routes->get('dashboard', 'Admin::dashboard');

    // Members
    $routes->get('members', 'Admin::members');
    $routes->post('members/add', 'Admin::addMember');
    $routes->post('members/deactivate/(:num)', 'Admin::deactivateMember/$1');
    $routes->post('members/reactivate/(:num)', 'Admin::reactivateMember/$1');
    $routes->post('members/(:num)/accounts/add', 'Admin::addAccount/$1');
    $routes->post('members/accounts/(:num)/deactivate', 'Admin::deactivateAccount/$1');
    $routes->post('members/accounts/(:num)/reactivate', 'Admin::reactivateAccount/$1');
    $routes->post('members/announce', 'Admin::announce');
    $routes->post('members/requests/(:num)/approve', 'Admin::approveAccountRequest/$1');
    $routes->post('members/requests/(:num)/reject', 'Admin::rejectAccountRequest/$1');

    // Contributions
    $routes->get('contributions', 'Admin::contributions');
    $routes->post('contributions/approve/(:num)', 'Admin::approveContribution/$1');  // FIX 4.3: POST
    $routes->post('contributions/reject/(:num)', 'Admin::rejectContribution/$1');

    // Loans
    $routes->get('loans', 'Admin::loans');
    $routes->get('loans/(:num)/schedule', 'Admin::loanSchedule/$1');
    $routes->post('loans/approve/(:num)', 'Admin::approveLoan/$1');
    $routes->post('loans/reject/(:num)', 'Admin::rejectLoan/$1');

    // Loan Payments
    $routes->get('payments', 'Admin::payments');
    $routes->post('payments/approve/(:num)', 'Admin::approvePayment/$1');   // FIX 4.3: POST
    $routes->post('payments/reject/(:num)', 'Admin::rejectPayment/$1');

    // Financial Records
    $routes->get('financial-records', 'Admin::financialRecords');
    $routes->post('financial-records/approve/(:num)', 'Admin::approveFinancialRecord/$1');  // FIX 4.3: POST
    $routes->post('financial-records/reject/(:num)', 'Admin::rejectFinancialRecord/$1');

    // Dividends
    $routes->get('dividends', 'Admin::dividends');
    $routes->post('dividends/distribute', 'Admin::distributeDividends');
    $routes->post('dividends/(:num)/approve', 'Admin::approveDividendDistribution/$1');
    $routes->post('dividends/(:num)/reject', 'Admin::rejectDividendDistribution/$1');

    // Blockchain ledger
    $routes->get('ledger', 'Admin::ledger');
    $routes->post('ledger/verify', 'Admin::verifyLedger');

    // Notifications
    $routes->get('notifications', 'Admin::notifications');
    $routes->post('notifications/(:num)/read', 'Admin::readNotification/$1');
    $routes->post('notifications/read-all', 'Admin::readAllNotifications');
    $routes->post('notifications/seen', 'Admin::seenNotifications');   // the bell was opened

    // Audit logs (super admin only; read-only)
    $routes->get('audit-logs', 'Admin::auditLogs');

    // Data retention review (super admin only)
    $routes->get('retention', 'Retention::index');
    $routes->post('retention/run', 'Retention::run');
    $routes->post('retention/hold', 'Retention::hold');
    $routes->post('retention/(:num)/remove-login', 'Retention::removeLoginData/$1');
    $routes->post('retention/(:num)/anonymize', 'Retention::anonymize/$1');

    // Analytics
    $routes->get('analytics', 'Admin::analytics');
    $routes->get('analytics/data', 'Admin::analyticsData');
    $routes->get('analytics/activity', 'Admin::analyticsActivity');

    // Reports
    $routes->get('reports', 'Admin::reports');
    $routes->get('reports/export', 'Admin::exportReport');

    // Data import (Excel)
    $routes->get('import', 'DataImport::index');
    $routes->get('import/template', 'DataImport::template');
    $routes->get('import/sample', 'DataImport::sample');
    $routes->post('import', 'DataImport::upload');

    // Settings
    $routes->get('settings', 'Settings::index');
    $routes->post('settings/password', 'Settings::changePassword');
    $routes->post('settings/admins', 'Settings::addAdmin');
    $routes->post('settings/updates', 'Settings::applyUpdates');
    $routes->post('settings/reset', 'Settings::resetData');
});

// Member routes (protected by auth filter)
$routes->group('member', ['filter' => 'auth:member'], function ($routes) {
    $routes->get('dashboard', 'Member::dashboard');
    $routes->get('contributions', 'Member::contributions');
    $routes->post('contributions/submit', 'Member::submitContribution');
    $routes->get('loans', 'Member::loans');
    $routes->post('loans/apply', 'Member::applyLoan');
    $routes->get('loans/(:num)/installments', 'Member::loanInstallments/$1');
    $routes->post('loans/(:num)/pay', 'Member::submitPayment/$1');
    $routes->get('dividends', 'Member::dividends');
    $routes->get('ledger', 'Member::ledger');
    $routes->get('profile', 'Member::profile');
    $routes->post('profile/accounts/add', 'Member::openAccount');
    $routes->post('profile/accounts/(:num)/deactivate', 'Member::requestAccountChange/$1/Deactivate');
    $routes->post('profile/accounts/(:num)/reactivate', 'Member::requestAccountChange/$1/Reactivate');
    $routes->get('payments', 'Member::payments');
    $routes->get('notifications', 'Member::notifications');
    $routes->post('notifications/(:num)/read', 'Member::readNotification/$1');
    $routes->post('notifications/read-all', 'Member::readAllNotifications');
    $routes->post('notifications/seen', 'Member::seenNotifications');
    $routes->get('analytics', 'Member::analytics');
    $routes->get('analytics/data', 'Member::analyticsData');
    $routes->get('analytics/activity', 'Member::analyticsActivity');
    $routes->get('settings', 'Settings::index');
    $routes->post('settings/password', 'Settings::changePassword');
});
