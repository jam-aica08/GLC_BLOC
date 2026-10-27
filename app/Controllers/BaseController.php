<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @param CLIRequest|IncomingRequest $request
     *
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    /**
     * JSON for the bell's background check (Notifier::adminFeed / memberFeed):
     * the unread count, the refreshed dropdown list, and the new notifications
     * to pop up, plus $extra (the admin's pending counts for the dashboard
     * tiles, so one background request serves both). Never cached: it is
     * private to the signed-in user.
     */
    protected function notificationFeedResponse(array $feed, string $area, array $extra = [])
    {
        $next = trim((string) $this->request->getGet('next'), '/');
        if (! preg_match('#^(admin|member)(/[A-Za-z0-9_/-]*)?$#', $next)) {
            $next = $area . '/dashboard';
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON($extra + [
            'unread' => $feed['unread'],
            'newest' => $feed['newest'],
            'html'   => view('partials/notification_list', ['notifications' => $feed['latest'], 'next' => $next, 'area' => $area]),
            'new'    => array_map(static fn ($n) => [
                'id'      => (int) $n['notification_id'],
                'title'   => $n['title'],
                'message' => $n['message'],
                'link'    => $n['link'] ? base_url($n['link']) : null,
            ], $feed['new']),
        ]);
    }
}
