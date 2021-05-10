<?php

declare(strict_types=1);

namespace Mezzio\Session\Cache\Exception;

use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\Cache\CacheSessionPersistenceFactory;
use RuntimeException;

use function sprintf;

class MissingDependencyException extends RuntimeException implements ExceptionInterface
{
    public static function forService(string $serviceName): self
    {
        return new self(sprintf(
            '%s requires the service "%s" in order to build a %s instance; none found',
            CacheSessionPersistenceFactory::class,
            $serviceName,
            CacheSessionPersistence::class
        ));
    }
}
