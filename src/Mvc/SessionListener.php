<?php declare(strict_types=1);

namespace NoEmptySession\Mvc;

use Laminas\Mvc\MvcEvent;
use Laminas\Session\Container;

/**
 * Defer the session cookie and the session write until there is data.
 *
 * By default, Omeka starts a session for each request because authentication
 * storage reads it, so each visitor gets a cookie and each request of a bot
 * without cookie creates a row in the table "session".
 * Here, when the request has no session cookie, the session is started without
 * cookie, then, at the end of the request, the cookie is sent and the session
 * is saved only when a value was stored, for example a csrf token, a message or
 *  the identity of a user.
 *
 * It is more privacy and gdpr compliant and increase performance a lot when
 * robots are numerous on a base stored on a mechanical disk.
 */
class SessionListener
{
    /**
     * @var bool
     */
    protected $deferred = false;

    public function onBootstrap(MvcEvent $event): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Same name than the one set by the core in bootstrapSession().
        $config = $event->getApplication()->getServiceManager()->get('Config');
        $name = $config['session']['config']['name'] ?? md5(OMEKA_PATH);
        if (isset($_COOKIE[$name])) {
            return;
        }

        ini_set('session.use_cookies', '0');
        $this->deferred = true;

        // The cookie is sent before the response. The shutdown function is
        // registered before the one of the session manager, so it runs first
        // and avoids the write when the response was sent outside of the mvc.
        $event->getApplication()->getEventManager()
            ->attach(MvcEvent::EVENT_FINISH, [$this, 'persist'], 100);
        register_shutdown_function([$this, 'persist']);
    }

    public function persist(): void
    {
        if (!$this->deferred || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $this->deferred = false;

        $storage = Container::getDefaultManager()->getStorage();
        if (static::hasData($storage->toArray())) {
            if (!headers_sent()) {
                $this->sendCookie();
            }
            return;
        }

        session_abort();
        $storage->markImmutable();
    }

    /**
     * Check if the session contains a value, except the metadata of Laminas and
     * the empty namespaces created by the containers read during request.
     */
    public static function hasData(iterable $data, bool $isRoot = true): bool
    {
        foreach ($data as $key => $value) {
            if ($isRoot && $key === '__Laminas') {
                continue;
            }
            if (is_iterable($value)
                ? static::hasData($value, false)
                : $value !== null && $value !== ''
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Send the session cookie like php does when "session.use_cookies" is on.
     */
    protected function sendCookie(): void
    {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => $params['lifetime'] ? time() + $params['lifetime'] : 0,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }
}
