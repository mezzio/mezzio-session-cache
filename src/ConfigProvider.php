<?php

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
