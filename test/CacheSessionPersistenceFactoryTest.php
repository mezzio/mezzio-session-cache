<?php

declare(strict_types=1);

namespace MezzioTest\Session\Cache;

use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\Cache\CacheSessionPersistenceFactory;
use Mezzio\Session\Cache\Exception;
use Mezzio\Session\Persistence\Http;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

use function gmdate;
use function time;

final class CacheSessionPersistenceFactoryTest extends TestCase
{
    /** @var ContainerInterface&MockObject */
    private ContainerInterface $container;

    public function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
    }

    /** @param mixed $expected */
    private function assertAttributeSame($expected, string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        self::assertSame($expected, $r->getValue($instance));
    }

    private function assertAttributeNotEmpty(string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        self::assertNotEmpty($r->getValue($instance));
    }

    public function testFactoryRaisesExceptionIfNoCacheAdapterAvailable(): void
    {
        $factory = new CacheSessionPersistenceFactory();

        $this->expectException(Exception\MissingDependencyException::class);
        $this->expectExceptionMessage(CacheItemPoolInterface::class);

        $factory(new InMemoryContainer());
    }

    public function testFactoryUsesSaneDefaultsForConstructorArguments(): void
    {
        $factory = new CacheSessionPersistenceFactory();

        $cachePool = $this->createMock(CacheItemPoolInterface::class);

        $container = new InMemoryContainer();
        $container->setService(CacheItemPoolInterface::class, $cachePool);
        $persistence = $factory($container);

        // This we provided
        self::assertAttributeSame($cachePool, 'cache', $persistence);

        // These we did not
        self::assertAttributeSame('PHPSESSION', 'cookieName', $persistence);
        self::assertAttributeSame('/', 'cookiePath', $persistence);
        self::assertAttributeSame(null, 'cookieDomain', $persistence);
        self::assertAttributeSame(false, 'cookieSecure', $persistence);
        self::assertAttributeSame(false, 'cookieHttpOnly', $persistence);
        self::assertAttributeSame('Lax', 'cookieSameSite', $persistence);
        self::assertAttributeSame('nocache', 'cacheLimiter', $persistence);
        self::assertAttributeSame(10800, 'cacheExpire', $persistence);
        self::assertAttributeNotEmpty('lastModified', $persistence);
        self::assertAttributeSame(false, 'persistent', $persistence);
        self::assertAttributeSame(true, 'autoRegenerate', $persistence);
    }

    public function testFactoryAllowsConfiguringAllConstructorArguments(): void
    {
        $factory      = new CacheSessionPersistenceFactory();
        $lastModified = time();
        $cachePool    = $this->createMock(CacheItemPoolInterface::class);
        $container    = new InMemoryContainer();
        $container->setService(CacheItemPoolInterface::class, $cachePool);
        $container->setService('config', [
            'mezzio-session-cache' => [
                'cookie_name'      => 'TESTING',
                'cookie_domain'    => 'example.com',
                'cookie_path'      => '/api',
                'cookie_secure'    => true,
                'cookie_http_only' => true,
                'cookie_same_site' => 'None',
                'cache_limiter'    => 'public',
                'cache_expire'     => 300,
                'last_modified'    => $lastModified,
                'persistent'       => true,
                'auto_regenerate'  => false,
            ],
        ]);

        $persistence = $factory($container);

        self::assertAttributeSame($cachePool, 'cache', $persistence);
        self::assertAttributeSame('TESTING', 'cookieName', $persistence);
        self::assertAttributeSame('/api', 'cookiePath', $persistence);
        self::assertAttributeSame('example.com', 'cookieDomain', $persistence);
        self::assertAttributeSame(true, 'cookieSecure', $persistence);
        self::assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        self::assertAttributeSame('None', 'cookieSameSite', $persistence);
        self::assertAttributeSame('public', 'cacheLimiter', $persistence);
        self::assertAttributeSame(300, 'cacheExpire', $persistence);
        self::assertAttributeSame(
            gmdate(Http::DATE_FORMAT, $lastModified),
            'lastModified',
            $persistence
        );
        self::assertAttributeSame(true, 'persistent', $persistence);
        self::assertAttributeSame(false, 'autoRegenerate', $persistence);
    }

    public function testFactoryAllowsConfiguringCacheAdapterServiceName(): void
    {
        $factory   = new CacheSessionPersistenceFactory();
        $cachePool = $this->createMock(CacheItemPoolInterface::class);
        $container = new InMemoryContainer();
        $container->setService('CacheService', $cachePool);
        $container->setService('config', [
            'mezzio-session-cache' => [
                'cache_item_pool_service' => 'CacheService',
            ],
        ]);

        $persistence = $factory($container);

        self::assertAttributeSame($cachePool, 'cache', $persistence);
    }

    public function testFactoryRaisesExceptionIfNamedCacheAdapterServiceIsUnavailable(): void
    {
        $factory   = new CacheSessionPersistenceFactory();
        $container = new InMemoryContainer();
        $container->setService('config', [
            'mezzio-session-cache' => [
                'cache_item_pool_service' => CacheSessionPersistence::class,
            ],
        ]);

        $this->expectException(Exception\MissingDependencyException::class);
        $this->expectExceptionMessage(CacheSessionPersistence::class);

        $factory($this->container);
    }
}
