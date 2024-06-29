<?php

declare(strict_types=1);

namespace Mezzio\Session\Cache;

use Mezzio\Session\InitializePersistenceIdInterface;
use Mezzio\Session\Persistence\CacheHeadersGeneratorTrait;
use Mezzio\Session\Persistence\Http;
use Mezzio\Session\Persistence\SessionCookieAwareTrait;
use Mezzio\Session\Session;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionPersistenceInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function bin2hex;
use function gmdate;
use function random_bytes;

/**
 * Session persistence using a PSR-16 cache adapter.
 *
 * Session identifiers are generated using random_bytes (and casting to hex).
 * During persistence, if the session regeneration flag is true, a new session
 * identifier is created, and the session re-started.
 */
class CacheSessionPersistence implements InitializePersistenceIdInterface, SessionPersistenceInterface
{
    use CacheHeadersGeneratorTrait;
    use SessionCookieAwareTrait;

    private CacheItemPoolInterface $cache;

    private bool $persistent;
    private bool $autoRegenerate;

    /**
     * Prepare session cache and default HTTP caching headers.
     *
     * @param CacheItemPoolInterface $cache The cache pool instance
     * @param string                 $cookieName The name of the cookie
     * @param string                 $cacheLimiter The cache limiter setting is used to
     *                     determine how to send HTTP client-side caching headers. Those
     *                     headers will be added programmatically to the response along with
     *                     the session set-cookie header when the session data is persisted.
     * @param int                    $cacheExpire Number of seconds until the session cookie
     *                        should expire; defaults to 180 minutes (180m * 60s/m = 10800s),
     *                        which is the default of the PHP session.cache_expire setting. This
     *                        is also used to set the TTL for session data.
     * @param null|int               $lastModified Timestamp when the application was last
     *                   modified. If not provided, this will look for each of
     *                   public/index.php, index.php, and finally the current working
     *                   directory, using the filemtime() of the first found.
     * @param bool                   $persistent Whether or not to create a persistent cookie. If
     *                       provided, this sets the Expires directive for the cookie based on
     *                       the value of $cacheExpire. Developers can also set the expiry at
     *                       runtime via the Session instance, using its persistSessionFor()
     *                       method; that value will be honored even if global persistence
     *                       is toggled true here.
     * @param string|null            $cookieDomain The domain for the cookie. If not set,
     *                the current domain is used.
     * @param bool                   $cookieSecure Whether or not the cookie should be required
     *                       to be set over an encrypted connection
     * @param bool                   $cookieHttpOnly Whether or not the cookie may be accessed
     *                       by client-side apis (e.g., Javascript). An http-only cookie cannot
     *                       be accessed by client-side apis.
     * @param string                 $cookieSameSite The same-site rule to apply to the persisted
     *                    cookie. Options include "Lax", "Strict", and "None".
     * @param bool                   $autoRegenerate Whether or not the session ID should be
     *                       regenerated on session data changes
     * @todo reorder the constructor arguments
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        string $cookieName,
        string $cookiePath = '/',
        string $cacheLimiter = 'nocache',
        int $cacheExpire = 10800,
        ?int $lastModified = null,
        bool $persistent = false,
        ?string $cookieDomain = null,
        bool $cookieSecure = false,
        bool $cookieHttpOnly = false,
        string $cookieSameSite = 'Lax',
        bool $autoRegenerate = true
    ) {
        $this->cache = $cache;

        if (empty($cookieName)) {
            throw new Exception\InvalidArgumentException('Session cookie name must not be empty');
        }
        $this->cookieName = $cookieName;

        $this->cookieLifetime = $persistent ? $cacheExpire : 0;

        $this->cookieDomain = $cookieDomain;

        $this->cookiePath = $cookiePath;

        $this->cookieSecure = $cookieSecure;

        $this->cookieHttpOnly = $cookieHttpOnly;

        $this->cookieSameSite = $cookieSameSite;

        $this->cacheLimiter = isset(self::$supportedCacheLimiters[$cacheLimiter])
            ? $cacheLimiter
            : 'nocache';

        $this->cacheExpire = $cacheExpire;

        $this->lastModified = $lastModified !== null
            ? gmdate(Http::DATE_FORMAT, $lastModified)
            : $this->getLastModified();

        $this->persistent = $persistent;

        $this->autoRegenerate = $autoRegenerate;
    }

    public function initializeSessionFromRequest(ServerRequestInterface $request): SessionInterface
    {
        $id          = $this->getSessionCookieValueFromRequest($request);
        $sessionData = $id ? $this->getSessionDataFromCache($id) : [];
        return new Session($sessionData, $id);
    }

    /**
     * @param SessionInterface&SessionIdentifierAwareInterface $session
     */
    public function persistSession(SessionInterface $session, ResponseInterface $response): ResponseInterface
    {
        $id = $session->getId();

        // New session? No data? Nothing to do.
        if (
            '' === $id
            && ([] === $session->toArray() || ! $session->hasChanged())
        ) {
            return $response;
        }

        // Regenerate the session if:
        // - we have no session identifier
        // - the session is marked as regenerated
        // - the session has changed (data is different) and autoRegenerate is turned on (default) in the configuration
        if ('' === $id || $session->isRegenerated() || ($this->autoRegenerate && $session->hasChanged())) {
            $id = $this->regenerateSession($id);
        }

        $this->persistSessionDataToCache($id, $session->toArray());

        $response = $this->addSessionCookieHeaderToResponse($response, $id, $session);
        $response = $this->addCacheHeadersToResponse($response);

        return $response;
    }

    /**
     * Regenerates the session.
     *
     * If the cache has an entry corresponding to `$id`, this deletes it.
     *
     * Regardless, it generates and returns a new session identifier.
     */
    private function regenerateSession(string $id): string
    {
        if ('' !== $id && $this->cache->hasItem($id)) {
            $this->cache->deleteItem($id);
        }
        return $this->generateSessionId();
    }

    /**
     * Generate a session identifier.
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function getSessionDataFromCache(string $id): array
    {
        $item = $this->cache->getItem($id);
        if (! $item->isHit()) {
            return [];
        }
        return $item->get() ?: [];
    }

    private function persistSessionDataToCache(string $id, array $data): void
    {
        $item = $this->cache->getItem($id);
        $item->set($data);
        $item->expiresAfter($this->cacheExpire);
        $this->cache->save($item);
    }

    public function initializeId(SessionInterface $session): SessionInterface
    {
        if ($session->getId() === '' || $session->isRegenerated()) {
            $session = new Session($session->toArray(), $this->generateSessionId());
        }

        return $session;
    }
}
