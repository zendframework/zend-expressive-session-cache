<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-session-cache for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-session-cache/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\Expressive\Session\Cache\Exception;

use RuntimeException;
use Zend\Expressive\Session\Cache\CacheSessionPersistence;
use Zend\Expressive\Session\Cache\CacheSessionPersistenceFactory;

class MissingDependencyException extends RuntimeException implements ExceptionInterface
{
    public static function forService(string $serviceName) : self
    {
        return new self(sprintf(
            '%s requires the service "%s" in order to build a %s instance; none found',
            CacheSessionPersistenceFactory::class,
            $serviceName,
            CacheSessionPersistence::class
        ));
    }
}
