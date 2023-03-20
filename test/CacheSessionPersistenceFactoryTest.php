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

class CacheSessionPersistenceFactoryTest extends TestCase
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
        $this->assertSame($expected, $r->getValue($instance));
    }

    private function assertAttributeNotEmpty(string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        $this->assertNotEmpty($r->getValue($instance));
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
        $this->assertAttributeSame($cachePool, 'cache', $persistence);

        // These we did not
        $this->assertAttributeSame('PHPSESSION', 'cookieName', $persistence);
        $this->assertAttributeSame('/', 'cookiePath', $persistence);
        $this->assertAttributeSame(null, 'cookieDomain', $persistence);
        $this->assertAttributeSame(false, 'cookieSecure', $persistence);
        $this->assertAttributeSame(false, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('Lax', 'cookieSameSite', $persistence);
        $this->assertAttributeSame('nocache', 'cacheLimiter', $persistence);
        $this->assertAttributeSame(10800, 'cacheExpire', $persistence);
        $this->assertAttributeNotEmpty('lastModified', $persistence);
        $this->assertAttributeSame(false, 'persistent', $persistence);
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
            ],
        ]);

        $persistence = $factory($container);

        $this->assertAttributeSame($cachePool, 'cache', $persistence);
        $this->assertAttributeSame('TESTING', 'cookieName', $persistence);
        $this->assertAttributeSame('/api', 'cookiePath', $persistence);
        $this->assertAttributeSame('example.com', 'cookieDomain', $persistence);
        $this->assertAttributeSame(true, 'cookieSecure', $persistence);
        $this->assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('None', 'cookieSameSite', $persistence);
        $this->assertAttributeSame('public', 'cacheLimiter', $persistence);
        $this->assertAttributeSame(300, 'cacheExpire', $persistence);
        $this->assertAttributeSame(
            gmdate(Http::DATE_FORMAT, $lastModified),
            'lastModified',
            $persistence
        );
        $this->assertAttributeSame(true, 'persistent', $persistence);
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

        $this->assertAttributeSame($cachePool, 'cache', $persistence);
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
