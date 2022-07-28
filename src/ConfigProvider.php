<?php

declare(strict_types=1);

namespace Mezzio\Session\Cache;

class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    /** @return array<string, mixed> */
    public function getDependencies(): array
    {
        return [
            // Legacy Zend Framework aliases
            'aliases'   => [
                'Zend\Expressive\Session\Cache\CacheSessionPersistence' => CacheSessionPersistence::class,
            ],
            'factories' => [
                CacheSessionPersistence::class => CacheSessionPersistenceFactory::class,
            ],
        ];
    }
}
