<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-cache for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-cache/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Mezzio\Session\Cache;

use Zend\Expressive\Session\Cache\CacheSessionPersistence as LegacyCacheSessionPersistence;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    public function getDependencies(): array
    {
        return [
            // Legacy Zend Framework aliases
            'aliases'   => [
                LegacyCacheSessionPersistence::class => CacheSessionPersistence::class,
            ],
            'factories' => [
                CacheSessionPersistence::class => CacheSessionPersistenceFactory::class,
            ],
        ];
    }
}
