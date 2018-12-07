<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-session-cache for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session-cache/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\Expressive\Session\Cache;

use DateInterval;
use DateTimeImmutable;
use Dflydev\FigCookies\FigRequestCookies;
use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\SetCookie;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Expressive\Session\Session;
use Zend\Expressive\Session\SessionCookiePersistenceInterface;
use Zend\Expressive\Session\SessionInterface;
use Zend\Expressive\Session\SessionPersistenceInterface;

use function bin2hex;
use function filemtime;
use function file_exists;
use function getcwd;
use function gmdate;
use function in_array;
use function random_bytes;
use function sprintf;
use function time;

/**
 * Session persistence using a PSR-16 cache adapter.
 *
 * Session identifiers are generated using random_bytes (and casting to hex).
 * During persistence, if the session regeneration flag is true, a new session
 * identifier is created, and the session re-started.
 */
class CacheSessionPersistence implements SessionPersistenceInterface
{
    /**
     * This unusual past date value is taken from the php-engine source code and
     * used "as is" for consistency.
     */
    public const CACHE_PAST_DATE  = 'Thu, 19 Nov 1981 08:52:00 GMT';

    public const HTTP_DATE_FORMAT = 'D, d M Y H:i:s T';

    /** @var array */
    private const SUPPORTED_CACHE_LIMITERS = [
        'nocache',
        'public',
        'private',
        'private_no_expire',
    ];

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var int */
    private $cacheExpire;

    /** @var string */
    private $cacheLimiter;

    /** @var string|null */
    private $cookie;

    /** @var string */
    private $cookieName;

    /** @var string|null */
    private $cookieDomain;

    /** @var string */
    private $cookiePath;

    /** @var bool */
    private $cookieSecure;

    /** @var bool */
    private $cookieHttpOnly;

    /** @var false|string */
    private $lastModified;

    /** @var bool */
    private $persistent;

    /**
     * Prepare session cache and default HTTP caching headers.
     *
     * The cache limiter setting is used to determine how to send HTTP
     * client-side caching headers. Those headers will be added
     * programmatically to the response along with the session set-cookie
     * header when the session data is persisted.
     *
     * @param int $cacheExpire Number of seconds until the session cookie
     *     should expire; defaults to 180 minutes (180m * 60s/m = 10800s),
     *     which is the default of the PHP session.cache_expire setting. This
     *     is also used to set the TTL for session data.
     * @param null|int $lastModified Timestamp when the application was last
     *     modified. If not provided, this will look for each of
     *     public/index.php, index.php, and finally the current working
     *     directory, using the filemtime() of the first found.
     * @param bool $persistent Whether or not to create a persistent cookie. If
     *     provided, this sets the Expires directive for the cookie based on
     *     the value of $cacheExpire. Developers can also set the expiry at
     *     runtime via the Session instance, using its persistSessionFor()
     *     method; that value will be honored even if global persistence
     *     is toggled true here.
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        string $cookieName,
        string $cookieDomain = null,
        string $cookiePath = '/',
        bool $cookieSecure = false,
        bool $cookieHttpOnly = false,
        string $cacheLimiter = 'nocache',
        int $cacheExpire = 10800,
        ?int $lastModified = null,
        bool $persistent = false
    ) {
        $this->cache = $cache;

        if (empty($cookieName)) {
            throw new Exception\InvalidArgumentException('Session cookie name must not be empty');
        }
        $this->cookieName = $cookieName;

        $this->cookieDomain = $cookieDomain;

        $this->cookiePath = $cookiePath;

        $this->cookieSecure = $cookieSecure;

        $this->cookieHttpOnly = $cookieHttpOnly;

        $this->cacheLimiter = in_array($cacheLimiter, self::SUPPORTED_CACHE_LIMITERS, true)
            ? $cacheLimiter
            : 'nocache';

        $this->cacheExpire = $cacheExpire;

        $this->lastModified = $lastModified
            ? gmdate(self::HTTP_DATE_FORMAT, $lastModified)
            : $this->determineLastModifiedValue();

        $this->persistent = $persistent;
    }

    public function initializeSessionFromRequest(ServerRequestInterface $request) : SessionInterface
    {
        $id = $this->getCookieFromRequest($request);
        $sessionData = $id ? $this->getSessionDataFromCache($id) : [];
        return new Session($sessionData, $id);
    }

    public function persistSession(SessionInterface $session, ResponseInterface $response) : ResponseInterface
    {
        $id = $session->getId();

        // New session? No data? Nothing to do.
        if ('' === $id
            && ([] === $session->toArray() || ! $session->hasChanged())
        ) {
            return $response;
        }

        // Regenerate the session if:
        // - we have no session identifier
        // - the session is marked as regenerated
        // - the session has changed (data is different)
        if ('' === $id || $session->isRegenerated() || $session->hasChanged()) {
            $id = $this->regenerateSession($id);
        }

        $this->persistSessionDataToCache($id, $session->toArray());

        $sessionCookie = SetCookie::create($this->cookieName)
            ->withValue($id)
            ->withDomain($this->cookieDomain)
            ->withPath($this->cookiePath)
            ->withSecure($this->cookieSecure)
            ->withHttpOnly($this->cookieHttpOnly);

        $persistenceDuration = $this->getPersistenceDuration($session);
        if ($persistenceDuration) {
            $sessionCookie = $sessionCookie->withExpires(
                (new DateTimeImmutable())->add(new DateInterval(sprintf('PT%dS', $persistenceDuration)))
            );
        }

        $response = FigResponseCookies::set($response, $sessionCookie);

        if ($this->responseAlreadyHasCacheHeaders($response)) {
            return $response;
        }

        foreach ($this->generateCacheHeaders() as $name => $value) {
            if (false !== $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Regenerates the session.
     *
     * If the cache has an entry corresponding to `$id`, this deletes it.
     *
     * Regardless, it generates and returns a new session identifier.
     */
    private function regenerateSession(string $id) : string
    {
        if ('' !== $id && $this->cache->hasItem($id)) {
            $this->cache->deleteItem($id);
        }
        return $this->generateSessionId();
    }

    /**
     * Generate a session identifier.
     */
    private function generateSessionId() : string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate cache http headers for this instance's session cache_limiter and
     * cache_expire values
     */
    private function generateCacheHeaders() : array
    {
        // cache_limiter: 'nocache'
        if ('nocache' === $this->cacheLimiter) {
            return [
                'Expires'       => self::CACHE_PAST_DATE,
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
            ];
        }

        // cache_limiter: 'public'
        if ('public' === $this->cacheLimiter) {
            return [
                'Expires'       => gmdate(self::HTTP_DATE_FORMAT, time() + $this->cacheExpire),
                'Cache-Control' => sprintf('public, max-age=%d', $this->cacheExpire),
                'Last-Modified' => $this->lastModified,
            ];
        }

        // cache_limiter: 'private'
        if ('private' === $this->cacheLimiter) {
            return [
                'Expires'       => self::CACHE_PAST_DATE,
                'Cache-Control' => sprintf('private, max-age=%d', $this->cacheExpire),
                'Last-Modified' => $this->lastModified,
            ];
        }

        // last possible case, cache_limiter = 'private_no_expire'
        return [
            'Cache-Control' => sprintf('private, max-age=%d', $this->cacheExpire),
            'Last-Modified' => $this->lastModified,
        ];
    }

    /**
     * Return the Last-Modified header line based on the request's script file
     * modified time. If no script file could be derived from the request we use
     * the file modification time of the current working directory as a fallback.
     *
     * @return string
     */
    private function determineLastModifiedValue() : string
    {
        $cwd = getcwd();
        foreach (['public/index.php', 'index.php'] as $filename) {
            $path = sprintf('%s/%s', $cwd, $filename);
            if (! file_exists($path)) {
                continue;
            }

            return gmdate(self::HTTP_DATE_FORMAT, filemtime($path));
        }

        return gmdate(self::HTTP_DATE_FORMAT, filemtime($cwd));
    }

    /**
     * Retrieve the session cookie value.
     *
     * Cookie headers may or may not be present, based on SAPI.  For instance,
     * under Swoole, they are omitted, but the cookie parameters are present.
     * As such, this method uses FigRequestCookies to retrieve the cookie value
     * only if the Cookie header is present. Otherwise, it falls back to the
     * request cookie parameters.
     *
     * In each case, if the value is not found, it falls back to generating a
     * new session identifier.
     */
    private function getCookieFromRequest(ServerRequestInterface $request) : string
    {
        if ('' !== $request->getHeaderLine('Cookie')) {
            return FigRequestCookies::get($request, $this->cookieName)->getValue() ?? '';
        }

        return $request->getCookieParams()[$this->cookieName] ?? '';
    }

    private function getSessionDataFromCache(string $id) : array
    {
        $item = $this->cache->getItem($id);
        if (! $item->isHit()) {
            return [];
        }
        return $item->get() ?: [];
    }

    private function persistSessionDataToCache(string $id, array $data) : void
    {
        $item = $this->cache->getItem($id);
        $item->set($data);
        $item->expiresAfter($this->cacheExpire);
        $this->cache->save($item);
    }

    /**
     * Check if the response already carries cache headers
     */
    private function responseAlreadyHasCacheHeaders(ResponseInterface $response) : bool
    {
        return (
            $response->hasHeader('Expires')
            || $response->hasHeader('Last-Modified')
            || $response->hasHeader('Cache-Control')
            || $response->hasHeader('Pragma')
        );
    }

    private function getPersistenceDuration(SessionInterface $session) : int
    {
        $duration = $this->persistent ? $this->cacheExpire : 0;
        if ($session instanceof SessionCookiePersistenceInterface
            && $session->has(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY)
        ) {
            $duration = $session->getSessionLifetime();
        }
        return $duration < 0 ? 0 : $duration;
    }
}
