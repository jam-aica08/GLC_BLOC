<?php

namespace App\Controllers;

use App\Libraries\DataReset;
use App\Libraries\DatabaseUpdates;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Settings for the signed-in user (admin or member): change password, and for
 * admins, create another administrator account.
 */
class Settings extends BaseController
{
    private function role(): string
    {
        return strtolower((string) session()->get('role'));
    }

    private function home(): string
    {
        return $this->role() === 'admin' ? '/admin/settings' : '/member/settings';
    }

    public function index(): string
    {
        return view('settings/index', [
            'layout'  => $this->role() === 'admin' ? 'layouts/main' : 'layouts/member_main',
            'isAdmin' => $this->role() === 'admin',
            'user'    => model(UserModel::class)->find((int) session()->get('user_id')),
            'dbStatus' => $this->role() === 'admin' ? DatabaseUpdates::status() : null,
            'recentErrors' => $this->role() === 'admin' ? $this->recentErrors() : [],
        ]);
    }

    /**
     * Last few critical errors from today's log, newest first, so an admin can
     * see what went wrong behind a "Whoops" page.
     */
    private function recentErrors(int $limit = 10): array
    {
        $file = WRITEPATH . 'logs/log-' . date('Y-m-d') . '.log';
        if (! is_file($file)) {
            return [];
        }

        $errors = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'CRITICAL') && ! str_contains($line, '[Caused by]')) {
                $errors[] = mb_strimwidth($line, 0, 400, '…');
            }
        }

        return array_slice(array_reverse(array_unique($errors)), 0, $limit);
    }

    /**
     * POST (admin): apply pending database updates (migrations).
     */
    public function applyUpdates(): RedirectResponse
    {
        if ($this->role() !== 'admin') {
            return redirect()->to('/login');
        }

        $error = DatabaseUpdates::apply();
        if ($error !== null) {
            return redirect()->to($this->home())->with('error', 'Database update failed: ' . $error);
        }

        return redirect()->to($this->home())->with('success', 'Database is up to date.');
    }

    /**
     * POST (admin): delete all loans, contributions, payments, dividends and
     * ledger entries (optionally members too). Needs "RESET" and the admin's password.
     */
    public function resetData(): RedirectResponse
    {
        if ($this->role() !== 'admin') {
            return redirect()->to('/login');
        }

        $user = model(UserModel::class)->find((int) session()->get('user_id'));
        if (trim((string) $this->request->getPost('confirm')) !== 'RESET') {
            return redirect()->to($this->home())->with('error', 'Type RESET in the box to confirm.');
        }
        if (! $user || ! password_verify((string) $this->request->getPost('password'), $user['password_hash'])) {
            return redirect()->to($this->home())->with('error', 'Your password is incorrect. Nothing was deleted.');
        }

        $includeMembers = $this->request->getPost('scope') === 'all';
        $result         = DataReset::run($includeMembers);
        if (is_string($result)) {
            return redirect()->to($this->home())->with('error', 'Reset failed, nothing was deleted: ' . $result);
        }

        $summary = [];
        foreach ($result as $table => $rows) {
            if ($rows > 0) {
                $summary[] = str_replace('_', ' ', $table) . ": {$rows}";
            }
        }

        return redirect()->to($this->home())->with('success', 'Data reset complete. Deleted — '
            . ($summary === [] ? 'nothing (already empty).' : implode(', ', $summary) . '.'));
    }

    public function changePassword(): RedirectResponse
    {
        $userModel = model(UserModel::class);
        $user      = $userModel->find((int) session()->get('user_id'));

        $current = (string) $this->request->getPost('current_password');
        $new     = (string) $this->request->getPost('new_password');
        $confirm = (string) $this->request->getPost('confirm_password');

        if (! $user || ! password_verify($current, $user['password_hash'])) {
            return redirect()->to($this->home())->with('error', 'Your current password is incorrect.');
        }
        if (strlen($new) < 8) {
            return redirect()->to($this->home())->with('error', 'The new password must be at least 8 characters.');
        }
        if ($new !== $confirm) {
            return redirect()->to($this->home())->with('error', 'The new passwords do not match.');
        }
        if (password_verify($new, $user['password_hash'])) {
            return redirect()->to($this->home())->with('error', 'The new password must be different from the current one.');
        }

        db_connect()->table('users')
                    ->where('user_id', $user['user_id'])
                    ->update(['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);

        return redirect()->to($this->home())->with('success', 'Your password has been changed.');
    }

    public function addAdmin(): RedirectResponse
    {
        if ($this->role() !== 'admin') {
            return redirect()->to('/login');
        }

        $username = trim((string) $this->request->getPost('username'));
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');

        if (strlen($password) < 8) {
            return redirect()->to($this->home())->with('error', 'The admin password must be at least 8 characters.');
        }

        $db     = db_connect();
        $roleId = $db->table('roles')->where('role_name', 'Admin')->get()->getRowArray()['role_id'] ?? null;

        $userModel = model(UserModel::class);
        $created   = $roleId && $userModel->insert([
            'role_id'        => $roleId,
            'username'       => $username,
            'email'          => $email,
            'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
            'account_status' => 'Active',
        ]);

        if (! $created) {
            return redirect()->to($this->home())->with('error', 'Could not create admin: ' . implode(' ', $userModel->errors()));
        }

        return redirect()->to($this->home())->with('success', "Administrator {$username} created.");
    }
}
