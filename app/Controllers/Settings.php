<?php

namespace App\Controllers;

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
            'pendingUpdates' => $this->role() === 'admin' ? DatabaseUpdates::pending() : [],
        ]);
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
