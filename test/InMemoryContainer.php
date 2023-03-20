<?php

declare(strict_types=1);

namespace MezzioTest\Session\Cache;

use Exception;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function array_key_exists;

final class InMemoryContainer implements ContainerInterface
{
    private array $services = [];

    /** @param string $id */
    public function get($id): mixed
    {
        if (! $this->has($id)) {
            throw new class ('Not Found') extends Exception implements NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    /** @param string $id */
    public function has($id): bool
    {
        return array_key_exists($id, $this->services);
    }

    public function setService(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }
}
