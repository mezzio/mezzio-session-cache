<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-cache for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-cache/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Session\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;

class CacheSessionPersistenceFactory
{
    public function __invoke(ContainerInterface $container)
    {
        $config = $container->has('config') ? $container->get('config') : [];
        $config = $config['mezzio-session-cache'] ?? [];

        $cacheService = $config['cache_item_pool_service'] ?? CacheItemPoolInterface::class;

        if (! $container->has($cacheService)) {
            throw Exception\MissingDependencyException::forService($cacheService);
        }

        $cookieName   = $config['cookie_name'] ?? 'PHPSESSION';
        $cookiePath   = $config['cookie_path'] ?? '/';
        $cacheLimiter = $config['cache_limiter'] ?? 'nocache';
        $cacheExpire  = $config['cache_expire'] ?? 10800;
        $lastModified = $config['last_modified'] ?? null;
        $persistent   = $config['persistent'] ?? false;

        return new CacheSessionPersistence(
            $container->get($cacheService),
            $cookieName,
            $cookiePath,
            $cacheLimiter,
            $cacheExpire,
            $lastModified,
            $persistent
        );
    }
}
