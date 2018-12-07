<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-session-cache for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session-cache/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace ZendTest\Expressive\Session\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Zend\Expressive\Session\Cache\CacheSessionPersistence;
use Zend\Expressive\Session\Cache\CacheSessionPersistenceFactory;
use Zend\Expressive\Session\Cache\Exception;

class CacheSessionPersistenceFactoryTest extends TestCase
{
    public function setUp()
    {
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function testFactoryRaisesExceptionIfNoCacheAdapterAvailable()
    {
        $factory = new CacheSessionPersistenceFactory();

        $this->container->has('config')->willReturn(false);
        $this->container->has(CacheItemPoolInterface::class)->willReturn(false);

        $this->expectException(Exception\MissingDependencyException::class);
        $this->expectExceptionMessage(CacheItemPoolInterface::class);

        $factory($this->container->reveal());
    }

    public function testFactoryUsesSaneDefaultsForConstructorArguments()
    {
        $factory = new CacheSessionPersistenceFactory();

        $cachePool = $this->prophesize(CacheItemPoolInterface::class)->reveal();

        $this->container->has('config')->willReturn(false);
        $this->container->has(CacheItemPoolInterface::class)->willReturn(true);
        $this->container->get(CacheItemPoolInterface::class)->willReturn($cachePool);

        $persistence = $factory($this->container->reveal());

        $this->assertInstanceOf(CacheSessionPersistence::class, $persistence);
        // This we provided
        $this->assertAttributeSame($cachePool, 'cache', $persistence);

        // These we did not
        $this->assertAttributeSame('PHPSESSION', 'cookieName', $persistence);
        $this->assertAttributeSame('/', 'cookiePath', $persistence);
        $this->assertAttributeSame(null, 'cookieDomain', $persistence);
        $this->assertAttributeSame(false, 'cookieSecure', $persistence);
        $this->assertAttributeSame(false, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('nocache', 'cacheLimiter', $persistence);
        $this->assertAttributeSame(10800, 'cacheExpire', $persistence);
        $this->assertAttributeNotEmpty('lastModified', $persistence);
        $this->assertAttributeSame(false, 'persistent', $persistence);
    }

    public function testFactoryAllowsConfiguringAllConstructorArguments()
    {
        $factory      = new CacheSessionPersistenceFactory();
        $lastModified = time();
        $cachePool    = $this->prophesize(CacheItemPoolInterface::class)->reveal();

        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn([
            'zend-expressive-session-cache' => [
                'cookie_name'      => 'TESTING',
                'cookie_domain'    => 'example.com',
                'cookie_path'      => '/api',
                'cookie_secure'    => true,
                'cookie_http_only' => true,
                'cache_limiter'    => 'public',
                'cache_expire'     => 300,
                'last_modified'    => $lastModified,
                'persistent'       => true,
            ],
        ]);
        $this->container->has(CacheItemPoolInterface::class)->willReturn(true);
        $this->container->get(CacheItemPoolInterface::class)->willReturn($cachePool);

        $persistence = $factory($this->container->reveal());

        $this->assertInstanceOf(CacheSessionPersistence::class, $persistence);
        $this->assertAttributeSame($cachePool, 'cache', $persistence);
        $this->assertAttributeSame('TESTING', 'cookieName', $persistence);
        $this->assertAttributeSame('/api', 'cookiePath', $persistence);
        $this->assertAttributeSame('example.com', 'cookieDomain', $persistence);
        $this->assertAttributeSame(true, 'cookieSecure', $persistence);
        $this->assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('public', 'cacheLimiter', $persistence);
        $this->assertAttributeSame(300, 'cacheExpire', $persistence);
        $this->assertAttributeSame(
            gmdate(CacheSessionPersistence::HTTP_DATE_FORMAT, $lastModified),
            'lastModified',
            $persistence
        );
        $this->assertAttributeSame(true, 'persistent', $persistence);
    }

    public function testFactoryAllowsConfiguringCacheAdapterServiceName()
    {
        $factory   = new CacheSessionPersistenceFactory();
        $cachePool = $this->prophesize(CacheItemPoolInterface::class)->reveal();

        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn([
            'zend-expressive-session-cache' => [
                'cache_item_pool_service' => 'CacheService',
            ],
        ]);
        $this->container->has('CacheService')->willReturn(true);
        $this->container->get('CacheService')->willReturn($cachePool);

        $persistence = $factory($this->container->reveal());

        $this->assertInstanceOf(CacheSessionPersistence::class, $persistence);
        $this->assertAttributeSame($cachePool, 'cache', $persistence);
    }

    public function testFactoryRaisesExceptionIfNamedCacheAdapterServiceIsUnavailable()
    {
        $factory = new CacheSessionPersistenceFactory();

        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn([
            'zend-expressive-session-cache' => [
                'cache_item_pool_service' => CacheSessionPersistence::class,
            ],
        ]);
        $this->container->has(CacheSessionPersistence::class)->willReturn(false);

        $this->expectException(Exception\MissingDependencyException::class);
        $this->expectExceptionMessage(CacheSessionPersistence::class);

        $factory($this->container->reveal());
    }
}
