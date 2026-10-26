<?php

namespace App\Controllers;

use App\Libraries\AccountAccess;
use App\Libraries\AuditLog;
use App\Services\RetentionService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Data retention: the public privacy notice, the super administrator's review
 * page (members due for review, legal holds, manual removal and
 * anonymization), and the daily review run by Vercel Cron.
 */
class Retention extends BaseController
{
    /** GET /privacy (public): what the cooperative keeps, why and for how long. */
    public function notice(): string
    {
        $role = strtolower((string) session()->get('role'));

        return view('privacy/notice', [
            'schedule' => config('Retention')->schedule(),
            'config'   => config('Retention'),
            'layout'   => session()->get('logged_in') ? ($role === 'admin' ? 'layouts/main' : 'layouts/member_main') : null,
        ]);
    }

    private function denied(): ?RedirectResponse
    {
        if (! AccountAccess::isSuperAdmin((int) session()->get('user_id'))) {
            return redirect()->to('/admin/dashboard')->with('error', 'Only the super administrator can manage data retention.');
        }
        if (! RetentionService::available()) {
            return redirect()->to('/admin/settings')->with('error', 'Data retention needs a database update. Apply the database updates below first.');
        }

        return null;
    }

    /** GET /admin/retention (super admin). */
    public function index()
    {
        if ($redirect = $this->denied()) {
            return $redirect;
        }
        $service = new RetentionService();

        return view('admin/retention', [
            'rows'      => $service->reviewList(),
            'holds'     => $service->holds(),
            'lastRun'   => RetentionService::lastRun(),
            'config'    => config('Retention'),
            'cronReady' => (string) getenv('CRON_SECRET') !== '',
        ]);
    }

    /** POST /admin/retention/run (super admin): run the daily review now. */
    public function run(): RedirectResponse
    {
        if ($redirect = $this->denied()) {
            return $redirect;
        }
        $summary = (new RetentionService())->run([
            'user_id'  => session()->get('user_id'),
            'username' => session()->get('username'),
            'role'     => 'Super Admin',
        ]);

        return redirect()->to('/admin/retention')->with('success', 'Retention review finished. ' . RetentionService::describe($summary));
    }

    /** POST /admin/retention/(:num)/remove-login (super admin). */
    public function removeLoginData(int $memberId): RedirectResponse
    {
        if ($redirect = $this->denied()) {
            return $redirect;
        }
        if (trim((string) $this->request->getPost('confirm')) !== 'REMOVE') {
            return redirect()->to('/admin/retention')->with('error', 'Type REMOVE to confirm.');
        }
        $error = (new RetentionService())->removeLoginData($memberId);

        return redirect()->to('/admin/retention')->with($error === null ? 'success' : 'error',
            $error ?? 'Sign-in data removed. The name and financial records are kept until the financial retention period ends.');
    }

    /** POST /admin/retention/(:num)/anonymize (super admin). */
    public function anonymize(int $memberId): RedirectResponse
    {
        if ($redirect = $this->denied()) {
            return $redirect;
        }
        if (trim((string) $this->request->getPost('confirm')) !== 'ANONYMIZE') {
            return redirect()->to('/admin/retention')->with('error', 'Type ANONYMIZE to confirm.');
        }
        $error = (new RetentionService())->anonymize($memberId);

        return redirect()->to('/admin/retention')->with($error === null ? 'success' : 'error',
            $error ?? 'Member anonymized. Their member number and financial totals remain for the books.');
    }

    /** POST /admin/retention/hold (super admin): place or release a legal hold. */
    public function hold(): RedirectResponse
    {
        if ($redirect = $this->denied()) {
            return $redirect;
        }
        $place    = $this->request->getPost('hold') === '1';
        $memberId = (int) $this->request->getPost('member_id');
        if (! $memberId && ($number = trim((string) $this->request->getPost('member_number'))) !== '') {
            $memberId = (int) (db_connect()->table('members')->select('member_id')->where('member_number', $number)->get()->getRowArray()['member_id'] ?? 0);
        }
        $error = (new RetentionService())->setHold($memberId, $place, (string) $this->request->getPost('reason'));

        return redirect()->to('/admin/retention')->with($error === null ? 'success' : 'error',
            $error ?? ($place ? 'Legal hold placed. Nothing is deactivated, removed or anonymized for this member until it is released.' : 'Legal hold released.'));
    }

    /**
     * GET /cron/retention: the daily review, called by Vercel Cron. Vercel
     * sends "Authorization: Bearer <CRON_SECRET>"; anything else gets 404, so
     * the endpoint is invisible without the secret.
     */
    public function cron()
    {
        $secret = (string) getenv('CRON_SECRET');
        $header = $this->request->getHeaderLine('Authorization');
        if ($secret === '' || ! hash_equals('Bearer ' . $secret, $header)) {
            return $this->response->setStatusCode(404);
        }

        try {
            $summary = (new RetentionService())->run();
        } catch (\Throwable $e) {
            log_message('critical', 'Retention review failed: ' . $e->getMessage());
            AuditLog::record('Ran data retention review', null, null, 'Failed: ' . $e->getMessage(), false,
                ['user_id' => null, 'username' => 'system', 'role' => 'System']);

            return $this->response->setStatusCode(500)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'summary' => $summary]);
    }
}
