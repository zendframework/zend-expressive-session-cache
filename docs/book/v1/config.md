# Configuration

This package allows configuring the following items:

- The PSR-6 `CacheItemPoolInterface` service to use.
- The session cookie name.
- The session cookie domain.
- The session cookie path.
- The session cookie secure option.
- The session cookie httponly option.
- The cache limiter (which controls how resources using sessions are cached by the browser).
- When the session expires.
- When the resource using a session was last modified.
- Whether or not to create a _persistent_ session cookie (i.e., one that will
  not expire when the browser is closed).

This document details how to configure each of these items.

## Config service

This package looks for a service named `config` that returns an array or
array-like value. Inside this value, it looks for a key named
`zend-expressive-session-cache`, which is expected to be an associative array or
object that acts like an associative array.

```php
return [
    'zend-expressive-session-cache' => [
        // key/value pairs
    ],
];
```

## CacheItemPoolInterface

By default, the factory will look for a service named
`Psr\Cache\CacheItemPoolInterface`. If found, that service will be used to seed
the persistence adapter.

You may also provide a `cache_item_pool_service` configuration value. If
present, this service name will be queried instead.

### Using a global pool

To use a global cache item pool, configure the PSR-6 `CacheItemPoolInterface`
service in your dependency configuration:

```php
use Psr\Cache\CacheItemPoolInterface;

return [
    'dependencies' => [
        'factories' => [
            CacheItemPoolInterface::class => FactoryProvidingACachePool::class,
        ],
    ],
];
```

### Using a named pool

To use a specific cache item pool:

```php
use Psr\Cache\CacheItemPoolInterface;

return [
    'dependencies' => [
        'factories' => [
            'MoreSpecificPool' => FactoryProvidingACachePool::class,
        ],
    ],
    'zend-expressive-session-cache' => [
        'cache_item_pool_service' => 'MoreSpecificPool',
    ],
];
```

## Non-Pool configuration

As noted earlier, you may configure a number of other values to customize your
persistence adapter. The following is example configuration, with inline
comments detailing expected and default values.

```php
use Psr\Cache\CacheItemPoolInterface;

return [
    'zend-expressive-session-cache' => [
        // Detailed in the above section; allows using a different
        // cache item pool than the global one.
        'cache_item_pool_service' => CacheItemPoolInterface::class,

        // The name of the session cookie. This name must comply with
        // the syntax outlined in https://tools.ietf.org/html/rfc6265.html
        'cookie_name' => 'PHPSESSION',

        // The (sub)domain that the cookie is available to. Setting this
        // to a subdomain (such as 'www.example.com') will make the cookie
        // available to that subdomain and all other sub-domains of it
        // (i.e. w2.www.example.com). To make the cookie available to the
        // whole domain (including all subdomains of it), simply set the
        // value to the domain name ('example.com', in this case).
        // Leave this null to use browser default (current hostname).
        'cookie_domain' => null,

        // The path prefix of the cookie domain to which it applies.
        'cookie_path' => '/',

        // Indicates that the cookie should only be transmitted over a
        // secure HTTPS connection from the client. When set to TRUE, the
        // cookie will only be set if a secure connection exists.
        'cookie_secure' => false,

        // When TRUE the cookie will be made accessible only through the
        // HTTP protocol. This means that the cookie won't be accessible
        // by scripting languages, such as JavaScript.
        'cookie_http_only' => false,

        // Governs the various cache control headers emitted when
        // a session cookie is provided to the client. Value may be one
        // of "nocache", "public", "private", or "private_no_expire";
        // semantics are the same as outlined in
        // http://php.net/session_cache_limiter
        'cache_limiter' => 'nocache',

        // When the cache and the cookie should expire, in seconds. Defaults
        // to 180 minutes.
        'cache_expire' => 10800,

        // An integer value indicating when the resource to which the session
        // applies was last modified. If not provided, it uses the last
        // modified time of, in order,
        // - the public/index.php file of the current working directory
        // - the index.php file of the current working directory
        // - the current working directory
        'last_modified' => null,

        // A boolean value indicating whether or not the session cookie
        // should persist. By default, this is disabled (false); passing
        // a boolean true value will enable the feature. When enabled, the
        // cookie will be generated with an Expires directive equal to the
        // the current time plus the cache_expire value as noted above.
        //
        // As of 1.2.0, developers may define the session TTL by calling the
        // session instance's `persistSessionFor(int $duration)` method. When
        // that method has been called, the engine will use that value even if
        // the below flag is toggled off.
        'persistent' => false,
    ],
];
```

## Using the service

By default, this package define the service `Zend\Expressive\Session\Cache\CacheSessionPersistence`,
assigning it to the factory `Zend\Expressive\Session\Cache\CacheSessionPersistenceFactory`.
After you have installed the package, you will need to tell your application to
use this service when using the `SessionMiddleware`.

The `SessionMiddleware` looks for the service `Zend\Expressive\Session\SessionPersistenceInterface`.
You can tell your container to use the `CacheSessionPersistence` in two
different ways.

First, you can _alias_ it:

```php
// in config/autoload/dependencies.global.php:
use Zend\Expressive\Session\Cache\CacheSessionPersistence;
use Zend\Expressive\Session\SessionPersistenceInterface;

return [
    'dependencies' => [
        'aliases' => [
            SessionPersistenceInterface::class => CacheSessionPersistence::class,
        ],
    ],
];
```

Second, you can instead assign the `SessionPersistenceInterface` service to the
factory for the `CacheSessionPersistence` implementation:

```php
// in config/autoload/dependencies.global.php:
use Zend\Expressive\Session\Cache\CacheSessionPersistenceFactory;
use Zend\Expressive\Session\SessionPersistenceInterface;

return [
    'dependencies' => [
        'factories' => [
            SessionPersistenceInterface::class => CacheSessionPersistenceFactory::class,
        ],
    ],
];
```
