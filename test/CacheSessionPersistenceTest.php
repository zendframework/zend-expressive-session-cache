<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-session-cache for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session-cache/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Session\Cache;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response;
use Zend\Expressive\Session\Cache\CacheSessionPersistence;
use Zend\Expressive\Session\Cache\Exception;
use Zend\Expressive\Session\Session;
use Zend\Expressive\Session\SessionCookiePersistenceInterface;

class CacheSessionPersistenceTest extends TestCase
{
    const GMDATE_REGEXP = '/[a-z]{3}, \d+ [a-z]{3} \d{4} \d{2}:\d{2}:\d{2} \w+$/i';

    const CACHE_HEADERS = [
        'cache-control',
        'expires',
        'last-modified',
        'pragma',
    ];

    public function setUp()
    {
        $this->cachePool = $this->prophesize(CacheItemPoolInterface::class);
        $this->currentTime = new DateTimeImmutable();
    }

    public function assertSetCookieUsesIdentifier(string $identifier, Response $response)
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertRegExp(
            '/test\=' . preg_quote($identifier, '/') . '/',
            $setCookie,
            sprintf(
                'Expected set-cookie header to contain "test=%s"; received "%s"',
                $identifier,
                $setCookie
            )
        );
    }

    public function assertSetCookieUsesNewIdentifier(string $identifier, Response $response)
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertNotRegExp(
            '/test=' . preg_quote($identifier, '/') . ';/',
            $setCookie,
            sprintf(
                'Expected set-cookie header NOT to contain "test=%s"; received "%s"',
                $identifier,
                $setCookie
            )
        );
    }

    public function assertCookieExpiryMirrorsExpiry(int $expiry, Response $response)
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $parts = explode(';', $setCookie);
        $parts = array_map(function ($value) {
            return trim($value);
        }, $parts);
        $parts = array_filter($parts, function ($value) {
            return (bool) preg_match('/^Expires=/', $value);
        });

        $this->assertSame(1, count($parts), 'No Expires directive found in cookie: ' . $setCookie);

        $compare = $this->currentTime->add(new DateInterval(sprintf('PT%dS', $expiry)));

        $value = array_shift($parts);
        [, $expires] = explode('=', $value);
        $expiresDate = new DateTimeImmutable($expires);

        $this->assertGreaterThanOrEqual(
            $expiresDate,
            $compare,
            sprintf('Cookie expiry "%s" is not at least "%s"', $expiresDate->format('r'), $compare->format('r'))
        );
    }

    public function assertCookieHasNoExpiryDirective(Response $response)
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $parts = explode(';', $setCookie);
        $parts = array_map(function ($value) {
            return trim($value);
        }, $parts);
        $parts = array_filter($parts, function ($value) {
            return (bool) preg_match('/^Expires=/', $value);
        });

        $this->assertSame(
            0,
            count($parts),
            'Expires directive found in cookie, but should not be present: ' . $setCookie
        );
    }

    public function assertCacheHeaders(string $cacheLimiter, Response $response)
    {
        switch ($cacheLimiter) {
            case 'nocache':
                return $this->assertNoCache($response);
            case 'public':
                return $this->assertCachePublic($response);
            case 'private':
                return $this->assertCachePrivate($response);
            case 'private_no_expire':
                return $this->assertCachePrivateNoExpire($response);
        }
    }

    public function assertNotCacheHeaders(array $allowed, Response $response)
    {
        $found = array_intersect(
            // headers that should not be present
            array_diff(self::CACHE_HEADERS, array_change_key_case($allowed, CASE_LOWER)),
            // what was sent
            array_change_key_case(array_keys($response->getHeaders()), CASE_LOWER)
        );
        $this->assertEquals(
            [],
            $found,
            sprintf(
                'One or more cache headers were found in the response that should not have been: %s',
                implode(', ', $found)
            )
        );
    }

    public function assertNoCache(Response $response)
    {
        $this->assertSame(
            CacheSessionPersistence::CACHE_PAST_DATE,
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected Expires header set to distant past; received "%s"',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertSame(
            'no-store, no-cache, must-revalidate',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to no-store, no-cache, must-revalidate; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertSame(
            'no-cache',
            $response->getHeaderLine('Pragma'),
            sprintf(
                'Expected Pragma header set to no-cache; received "%s"',
                $response->getHeaderLine('Pragma')
            )
        );
    }

    public function assertCachePublic(Response $response)
    {
        $this->assertRegExp(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected Expires header with RFC formatted date; received %s',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertRegExp(
            '/^public, max-age=\d+$/',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to public, with max-age; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertRegExp(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Last-Modified'),
            sprintf(
                'Expected Last-Modified header with RFC formatted date; received %s',
                $response->getHeaderLine('Last-Modified')
            )
        );
    }

    public function assertCachePrivate(Response $response)
    {
        $this->assertSame(
            CacheSessionPersistence::CACHE_PAST_DATE,
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected Expires header set to distant past; received "%s"',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertRegExp(
            '/^private, max-age=\d+$/',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to private, with max-age; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertRegExp(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Last-Modified'),
            sprintf(
                'Expected Last-Modified header with RFC formatted date; received %s',
                $response->getHeaderLine('Last-Modified')
            )
        );
    }

    public function assertCachePrivateNoExpire(Response $response)
    {
        $this->assertSame(
            '',
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected empty/missing Expires header; received "%s"',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertRegExp(
            '/^private, max-age=\d+$/',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to private, with max-age; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertRegExp(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Last-Modified'),
            sprintf(
                'Expected Last-Modified header with RFC formatted date; received %s',
                $response->getHeaderLine('Last-Modified')
            )
        );
    }

    public function testConstructorRaisesExceptionForEmptyCookieName()
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new CacheSessionPersistence($this->cachePool->reveal(), '');
    }

    public function testConstructorUsesDefaultsForOptionalArguments()
    {
        $persistence = new CacheSessionPersistence($this->cachePool->reveal(), 'test');

        // These are what we provided
        $this->assertAttributeSame($this->cachePool->reveal(), 'cache', $persistence);
        $this->assertAttributeSame('test', 'cookieName', $persistence);

        // These we did not
        $this->assertAttributeSame(null, 'cookieDomain', $persistence);
        $this->assertAttributeSame('/', 'cookiePath', $persistence);
        $this->assertAttributeSame(false, 'cookieSecure', $persistence);
        $this->assertAttributeSame(false, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('nocache', 'cacheLimiter', $persistence);
        $this->assertAttributeSame(10800, 'cacheExpire', $persistence);
        $this->assertAttributeNotEmpty('lastModified', $persistence);
    }

    public function validCacheLimiters() : array
    {
        return [
            'nocache'           => ['nocache'],
            'public'            => ['public'],
            'private'           => ['private'],
            'private_no_expire' => ['private_no_expire'],
        ];
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testConstructorAllowsProvidingAllArguments($cacheLimiter)
    {
        $lastModified = time() - 3600;

        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            'example.com',
            '/api',
            true,
            true,
            $cacheLimiter,
            100,
            $lastModified
        );

        $this->assertAttributeSame($this->cachePool->reveal(), 'cache', $persistence);
        $this->assertAttributeSame('test', 'cookieName', $persistence);
        $this->assertAttributeSame('/api', 'cookiePath', $persistence);
        $this->assertAttributeSame('example.com', 'cookieDomain', $persistence);
        $this->assertAttributeSame(true, 'cookieSecure', $persistence);
        $this->assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame($cacheLimiter, 'cacheLimiter', $persistence);
        $this->assertAttributeSame(100, 'cacheExpire', $persistence);
        $this->assertAttributeSame(
            gmdate(CacheSessionPersistence::HTTP_DATE_FORMAT, $lastModified),
            'lastModified',
            $persistence
        );
    }

    public function testDefaultsToNocacheIfInvalidCacheLimiterProvided()
    {
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            'example.com',
            '/api',
            true,
            true,
            'not-valid'
        );

        $this->assertAttributeSame($this->cachePool->reveal(), 'cache', $persistence);
        $this->assertAttributeSame('test', 'cookieName', $persistence);
        $this->assertAttributeSame('example.com', 'cookieDomain', $persistence);
        $this->assertAttributeSame('/api', 'cookiePath', $persistence);
        $this->assertAttributeSame(true, 'cookieSecure', $persistence);
        $this->assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('nocache', 'cacheLimiter', $persistence);
    }

    public function testInitializeSessionFromRequestReturnsSessionWithEmptyIdentifierAndDataIfNoCookieFound()
    {
        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getHeaderLine('Cookie')->willReturn('');
        $request->getCookieParams()->willReturn([]);

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(false);
        $this->cachePool->getItem('')->will([$cacheItem, 'reveal']);

        $persistence = new CacheSessionPersistence($this->cachePool->reveal(), 'test');

        $session = $persistence->initializeSessionFromRequest($request->reveal());

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame('', $session->getId());
        $this->assertSame([], $session->toArray());
    }

    public function testInitializeSessionFromRequestReturnsSessionDataUsingCookieHeaderValue()
    {
        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getHeaderLine('Cookie')->willReturn('test=identifier');
        $request->getCookieParams()->shouldNotBeCalled([]);

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(true);
        $cacheItem->get()->willReturn(['foo' => 'bar']);
        $this->cachePool->getItem('identifier')->will([$cacheItem, 'reveal']);

        $persistence = new CacheSessionPersistence($this->cachePool->reveal(), 'test');

        $session = $persistence->initializeSessionFromRequest($request->reveal());

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame('identifier', $session->getId());
        $this->assertSame(['foo' => 'bar'], $session->toArray());
    }

    public function testInitializeSessionFromRequestReturnsSessionDataUsingCookieParamsWhenHeaderNotFound()
    {
        $request = $this->prophesize(ServerRequestInterface::class);
        $request->getHeaderLine('Cookie')->willReturn('');
        $request->getCookieParams()->willReturn(['test' => 'identifier']);

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->isHit()->willReturn(true);
        $cacheItem->get()->willReturn(['foo' => 'bar']);
        $this->cachePool->getItem('identifier')->will([$cacheItem, 'reveal']);

        $persistence = new CacheSessionPersistence($this->cachePool->reveal(), 'test');

        $session = $persistence->initializeSessionFromRequest($request->reveal());

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame('identifier', $session->getId());
        $this->assertSame(['foo' => 'bar'], $session->toArray());
    }

    public function testPersistSessionWithNoIdentifierAndNoDataReturnsResponseVerbatim()
    {
        $session = new Session([], '');
        $response = new Response();
        $persistence = new CacheSessionPersistence($this->cachePool->reveal(), 'test');

        $result = $persistence->persistSession($session, $response);

        $this->cachePool->getItem(Argument::any())->shouldNotHaveBeenCalled();
        $this->cachePool->save(Argument::any())->shouldNotHaveBeenCalled();
        $this->assertSame($response, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionWithNoIdentifierAndPopulatedDataPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session([], '');
        $session->set('foo', 'bar');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'bar'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionWithIdentifierAndPopulatedDataPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'bar'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->getItem('identifier')->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionRequestingRegenerationPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session = $session->regenerate();
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'bar'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();

        // This emulates a scenario when the session does not exist in the cache
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool->deleteItem(Argument::any())->shouldNotBeCalled();

        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertNotSame('identifier', $value);
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionRequestingRegenerationRemovesPreviousSession(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session = $session->regenerate();
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'bar'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();

        // This emulates an existing session existing.
        $this->cachePool->hasItem('identifier')->willReturn(true);
        $this->cachePool->deleteItem(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertNotSame('identifier', $value);
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionWithIdentifierAndChangedDataPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session->set('foo', 'baz');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'baz'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();

        // This emulates a scenario when the session does not exist in the cache
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool->deleteItem(Argument::any())->shouldNotBeCalled();

        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertNotSame('identifier', $value);
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionDeletesPreviousSessionIfItExists(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session->set('foo', 'baz');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'baz'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();

        // This emulates an existing session existing.
        $this->cachePool->hasItem('identifier')->willReturn(true);
        $this->cachePool->deleteItem(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertNotSame('identifier', $value);
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    public function cacheHeaders() : iterable
    {
        foreach (self::CACHE_HEADERS as $header) {
            yield $header => [$header];
        }
    }

    /**
     * @dataProvider cacheHeaders
     */
    public function testPersistSessionWithAnyExistingCacheHeadersDoesNotRepopulateCacheHeaders(string $header)
    {
        $session = new Session([], '');
        $session->set('foo', 'bar');

        $response = new Response();
        $response = $response->withHeader($header, 'some value');

        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test'
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'bar'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('', $result);
        $this->assertNotCacheHeaders([$header], $result);
    }

    public function testPersistentSessionCookieIncludesExpiration()
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem->set(['foo' => 'bar'])->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool
            ->getItem('identifier')
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(600, $result);
    }

    public function testPersistenceDurationSpecifiedInSessionUsedWhenPresentEvenWhenEngineDoesNotSpecifyPersistence()
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();

        // Engine created with defaults, which means no cookie persistence
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test'
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem
            ->set(Argument::that(function ($value) {
                TestCase::assertInternalType('array', $value);
                TestCase::assertArrayHasKey('foo', $value);
                TestCase::assertSame('bar', $value['foo']);
                TestCase::assertArrayHasKey(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
                TestCase::assertSame(1200, $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY]);
                return $value;
            }))
            ->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $session->persistSessionFor(1200);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(1200, $result);
    }

    public function testPersistenceDurationSpecifiedInSessionOverridesExpiryWhenSessionPersistenceIsEnabled()
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem
            ->set(Argument::that(function ($value) {
                TestCase::assertInternalType('array', $value);
                TestCase::assertArrayHasKey('foo', $value);
                TestCase::assertSame('bar', $value['foo']);
                TestCase::assertArrayHasKey(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
                TestCase::assertSame(1200, $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY]);
                return $value;
            }))
            ->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $session->persistSessionFor(1200);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(1200, $result);
    }

    public function testPersistenceDurationOfZeroSpecifiedInSessionDisablesPersistence()
    {
        $session = new Session([
            'foo' => 'bar',
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => 1200,
        ], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test'
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem
            ->set(Argument::that(function ($value) {
                TestCase::assertInternalType('array', $value);
                TestCase::assertArrayHasKey('foo', $value);
                TestCase::assertSame('bar', $value['foo']);
                TestCase::assertArrayHasKey(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
                TestCase::assertSame(0, $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY]);
                return $value;
            }))
            ->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $session->persistSessionFor(0);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieHasNoExpiryDirective($result);
    }

    public function testPersistenceDurationOfZeroWithoutSessionLifetimeKeyInDataResultsInGlobalPersistenceExpiry()
    {
        // No previous session lifetime set
        $session = new Session([
            'foo' => 'bar',
        ], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem
            ->set(Argument::that(function ($value) {
                TestCase::assertInternalType('array', $value);
                TestCase::assertArrayHasKey('foo', $value);
                TestCase::assertSame('bar', $value['foo']);
                TestCase::assertArrayNotHasKey(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
                return $value;
            }))
            ->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertSame('identifier', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        $result = $persistence->persistSession($session, $response);

        $this->assertSame(0, $session->getSessionLifetime());
        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(600, $result);
    }

    public function testPersistenceDurationOfZeroIgnoresGlobalPersistenceExpiry()
    {
        $session = new Session([
            'foo' => 'bar',
        ], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem
            ->set(Argument::that(function ($value) {
                TestCase::assertInternalType('array', $value);
                TestCase::assertArrayHasKey('foo', $value);
                TestCase::assertSame('bar', $value['foo']);
                TestCase::assertArrayHasKey(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
                TestCase::assertSame(0, $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY]);
                return $value;
            }))
            ->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        // Calling persistSessionFor sets the session lifetime key in the data,
        // which allows us to override the value.
        $session->persistSessionFor(0);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieHasNoExpiryDirective($result);
    }

    public function testPersistenceDurationInSessionDataWithValueOfZeroIgnoresGlobalPersistenceExpiry()
    {
        $session = new Session([
            'foo' => 'bar',
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => 0,
        ], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool->reveal(),
            'test',
            null,
            '/',
            false,
            false,
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->prophesize(CacheItemInterface::class);
        $cacheItem
            ->set(Argument::that(function ($value) {
                TestCase::assertInternalType('array', $value);
                TestCase::assertArrayHasKey('foo', $value);
                TestCase::assertSame('baz', $value['foo']);
                TestCase::assertArrayHasKey(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
                TestCase::assertSame(0, $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY]);
                return $value;
            }))
            ->shouldBeCalled();
        $cacheItem->expiresAfter(Argument::type('int'))->shouldBeCalled();
        $this->cachePool->hasItem('identifier')->willReturn(false);
        $this->cachePool
            ->getItem(Argument::that(function ($value) {
                TestCase::assertRegExp('/^[a-f0-9]{32}$/', $value);
                return $value;
            }))
            ->will([$cacheItem, 'reveal']);
        $this->cachePool->save(Argument::that([$cacheItem, 'reveal']))->shouldBeCalled();

        // Changing the data, to ensure we trigger a new session cookie
        $session->set('foo', 'baz');
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieHasNoExpiryDirective($result);
    }
}
