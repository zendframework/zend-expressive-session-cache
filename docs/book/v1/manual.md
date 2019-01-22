# Manual usage

The following details the constructor of the `Zend\Expressive\Session\Cache\CacheSessionPersistence` class:

```php
/**
 * Prepare session cache and default HTTP caching headers.
 *
 * @param CacheItemPoolInterface $cache The cache pool instance
 * @param string $cookieName The name of the cookie
 * @param string $cacheLimiter The cache limiter setting is used to
 *     determine how to send HTTP client-side caching headers. Those
 *     headers will be added programmatically to the response along with
 *     the session set-cookie header when the session data is persisted.
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
 * @param string|null $cookieDomain The domain for the cookie. If not set,
 *     the current domain is used.
 * @param bool $cookieSecure Whether or not the cookie should be required
 *     to be set over an encrypted connection
 * @param bool $cookieHttpOnly Whether or not the cookie may be accessed
 *     by client-side apis (e.g., Javascript). An http-only cookie cannot
 *     be accessed by client-side apis.
 *
 * @todo reorder these arguments so they make more sense and are in an
 *     order of importance
 */
public function __construct(
    CacheItemPoolInterface $cache,
    string $cookieName,
    string $cookiePath = '/',
    string $cacheLimiter = 'nocache',
    int $cacheExpire = 10800,
    ?int $lastModified = null,
    bool $persistent = false,
    string $cookieDomain = null,
    bool $cookieSecure = false,
    bool $cookieHttpOnly = false
) {
```

Pass all required values and any optional values when creating an instance:

```php
use Cache\Adapter\Predis\PredisCachePool;
use Zend\Expressive\Session\Cache\CacheSessionPersistence;
use Zend\Expressive\Session\SessionMiddleware;

$cachePool = new PredisCachePool('tcp://localhost:6379');
$persistence = new CacheSessionPersistence(
    $cachePool,
    'MYSITE'
    '/',
    'public',
    60 * 60 * 24 * 30 // 30 days
);
$middleware = new SessionMiddleware($persistence);
```
