<?php

declare(strict_types=1);

namespace MezzioTest\Session\Cache;

use DateInterval;
use DateTimeImmutable;
use Laminas\Diactoros\Response;
use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\Cache\Exception;
use Mezzio\Session\Persistence\Http;
use Mezzio\Session\Session;
use Mezzio\Session\SessionCookiePersistenceInterface;
use Mezzio\Session\SessionIdentifierAwareInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;

use function array_change_key_case;
use function array_diff;
use function array_filter;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function count;
use function explode;
use function gmdate;
use function implode;
use function preg_match;
use function preg_quote;
use function sprintf;
use function time;
use function trim;

use const CASE_LOWER;

class CacheSessionPersistenceTest extends TestCase
{
    public const GMDATE_REGEXP = '/[a-z]{3}, \d+ [a-z]{3} \d{4} \d{2}:\d{2}:\d{2} \w+$/i';

    public const CACHE_HEADERS = [
        'cache-control',
        'expires',
        'last-modified',
        'pragma',
    ];

    /** @var CacheItemPoolInterface&MockObject */
    private CacheItemPoolInterface $cachePool;

    private DateTimeImmutable $currentTime;

    public function setUp(): void
    {
        $this->cachePool   = $this->createMock(CacheItemPoolInterface::class);
        $this->currentTime = new DateTimeImmutable(gmdate(Http::DATE_FORMAT));
    }

    private function assertAttributeSame(mixed $expected, string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        $this->assertSame($expected, $r->getValue($instance));
    }

    private function assertAttributeNotEmpty(string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        $this->assertNotEmpty($r->getValue($instance));
    }

    public function assertSetCookieUsesIdentifier(string $identifier, ResponseInterface $response): void
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertMatchesRegularExpression(
            '/test\=' . preg_quote($identifier, '/') . '/',
            $setCookie,
            sprintf(
                'Expected set-cookie header to contain "test=%s"; received "%s"',
                $identifier,
                $setCookie
            )
        );
    }

    public function assertSetCookieUsesNewIdentifier(string $identifier, ResponseInterface $response): void
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertDoesNotMatchRegularExpression(
            '/test=' . preg_quote($identifier, '/') . ';/',
            $setCookie,
            sprintf(
                'Expected set-cookie header NOT to contain "test=%s"; received "%s"',
                $identifier,
                $setCookie
            )
        );
    }

    public function assertCookieExpiryMirrorsExpiry(int $expiry, ResponseInterface $response): void
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $parts     = explode(';', $setCookie);
        $parts     = array_map(static fn($value) => trim($value), $parts);
        $parts     = array_filter($parts, static fn(string $value) => (bool) preg_match('/^Expires=/', $value));

        $this->assertSame(1, count($parts), 'No Expires directive found in cookie: ' . $setCookie);

        $compare = $this->currentTime->add(new DateInterval(sprintf('PT%dS', $expiry)));

        $value       = array_shift($parts);
        [, $expires] = explode('=', $value);
        $expiresDate = new DateTimeImmutable($expires);

        $this->assertGreaterThanOrEqual(
            $compare,
            $expiresDate,
            sprintf('Cookie expiry "%s" is not at least "%s"', $expiresDate->format('r'), $compare->format('r'))
        );
    }

    public function assertCookieHasNoExpiryDirective(ResponseInterface $response): void
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $parts     = explode(';', $setCookie);
        $parts     = array_map(static fn($value) => trim($value), $parts);
        $parts     = array_filter($parts, static fn(string $value) => (bool) preg_match('/^Expires=/', $value));

        $this->assertSame(
            0,
            count($parts),
            'Expires directive found in cookie, but should not be present: ' . $setCookie
        );
    }

    public function assertCacheHeaders(string $cacheLimiter, ResponseInterface $response): void
    {
        switch ($cacheLimiter) {
            case 'nocache':
                $this->assertNoCache($response);
                return;
            case 'public':
                $this->assertCachePublic($response);
                return;
            case 'private':
                $this->assertCachePrivate($response);
                return;
            case 'private_no_expire':
                $this->assertCachePrivateNoExpire($response);
                return;
            default:
                $this->fail('Invalid cache limiter provided to ' . __FUNCTION__);
        }
    }

    public function assertNotCacheHeaders(array $allowed, ResponseInterface $response): void
    {
        $found = array_intersect(
            // headers that should not be present
            array_diff(self::CACHE_HEADERS, array_change_key_case($allowed, CASE_LOWER)),
            // what was sent
            array_change_key_case(array_keys($response->getHeaders()), CASE_LOWER)
        );
        $this->assertEquals(
            [],
            $found,
            sprintf(
                'One or more cache headers were found in the response that should not have been: %s',
                implode(', ', $found)
            )
        );
    }

    public function assertNoCache(ResponseInterface $response): void
    {
        $this->assertSame(
            Http::CACHE_PAST_DATE,
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected Expires header set to distant past; received "%s"',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertSame(
            'no-store, no-cache, must-revalidate',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to no-store, no-cache, must-revalidate; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertSame(
            'no-cache',
            $response->getHeaderLine('Pragma'),
            sprintf(
                'Expected Pragma header set to no-cache; received "%s"',
                $response->getHeaderLine('Pragma')
            )
        );
    }

    public function assertCachePublic(ResponseInterface $response): void
    {
        $this->assertMatchesRegularExpression(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected Expires header with RFC formatted date; received %s',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertMatchesRegularExpression(
            '/^public, max-age=\d+$/',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to public, with max-age; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertMatchesRegularExpression(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Last-Modified'),
            sprintf(
                'Expected Last-Modified header with RFC formatted date; received %s',
                $response->getHeaderLine('Last-Modified')
            )
        );
    }

    public function assertCachePrivate(ResponseInterface $response): void
    {
        $this->assertSame(
            Http::CACHE_PAST_DATE,
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected Expires header set to distant past; received "%s"',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertMatchesRegularExpression(
            '/^private, max-age=\d+$/',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to private, with max-age; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertMatchesRegularExpression(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Last-Modified'),
            sprintf(
                'Expected Last-Modified header with RFC formatted date; received %s',
                $response->getHeaderLine('Last-Modified')
            )
        );
    }

    public function assertCachePrivateNoExpire(ResponseInterface $response): void
    {
        $this->assertSame(
            '',
            $response->getHeaderLine('Expires'),
            sprintf(
                'Expected empty/missing Expires header; received "%s"',
                $response->getHeaderLine('Expires')
            )
        );
        $this->assertMatchesRegularExpression(
            '/^private, max-age=\d+$/',
            $response->getHeaderLine('Cache-Control'),
            sprintf(
                'Expected Cache-Control header set to private, with max-age; received "%s"',
                $response->getHeaderLine('Cache-Control')
            )
        );
        $this->assertMatchesRegularExpression(
            self::GMDATE_REGEXP,
            $response->getHeaderLine('Last-Modified'),
            sprintf(
                'Expected Last-Modified header with RFC formatted date; received %s',
                $response->getHeaderLine('Last-Modified')
            )
        );
    }

    public function testConstructorRaisesExceptionForEmptyCookieName(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new CacheSessionPersistence($this->cachePool, '');
    }

    public function testConstructorUsesDefaultsForOptionalArguments(): void
    {
        $persistence = new CacheSessionPersistence($this->cachePool, 'test');

        // These are what we provided
        $this->assertAttributeSame($this->cachePool, 'cache', $persistence);
        $this->assertAttributeSame('test', 'cookieName', $persistence);

        // These we did not
        $this->assertAttributeSame(null, 'cookieDomain', $persistence);
        $this->assertAttributeSame('/', 'cookiePath', $persistence);
        $this->assertAttributeSame(false, 'cookieSecure', $persistence);
        $this->assertAttributeSame(false, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('Lax', 'cookieSameSite', $persistence);
        $this->assertAttributeSame('nocache', 'cacheLimiter', $persistence);
        $this->assertAttributeSame(10800, 'cacheExpire', $persistence);
        $this->assertAttributeNotEmpty('lastModified', $persistence);
    }

    /**
     * @psalm-return array<string, array{string}>
     */
    public static function validCacheLimiters(): array
    {
        return [
            'nocache'           => ['nocache'],
            'public'            => ['public'],
            'private'           => ['private'],
            'private_no_expire' => ['private_no_expire'],
        ];
    }

    #[DataProvider('validCacheLimiters')]
    public function testConstructorAllowsProvidingAllArguments(string $cacheLimiter): void
    {
        $lastModified = time() - 3600;

        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/api',
            $cacheLimiter,
            100,
            $lastModified,
            false,
            'example.com',
            true,
            true,
            'None'
        );

        $this->assertAttributeSame($this->cachePool, 'cache', $persistence);
        $this->assertAttributeSame('test', 'cookieName', $persistence);
        $this->assertAttributeSame('/api', 'cookiePath', $persistence);
        $this->assertAttributeSame('example.com', 'cookieDomain', $persistence);
        $this->assertAttributeSame(true, 'cookieSecure', $persistence);
        $this->assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('None', 'cookieSameSite', $persistence);
        $this->assertAttributeSame($cacheLimiter, 'cacheLimiter', $persistence);
        $this->assertAttributeSame(100, 'cacheExpire', $persistence);
        $this->assertAttributeSame(
            gmdate(Http::DATE_FORMAT, $lastModified),
            'lastModified',
            $persistence
        );
    }

    public function testDefaultsToNocacheIfInvalidCacheLimiterProvided(): void
    {
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/api',
            'not-valid',
            100,
            null,
            false,
            'example.com',
            true,
            true
        );

        $this->assertAttributeSame($this->cachePool, 'cache', $persistence);
        $this->assertAttributeSame('test', 'cookieName', $persistence);
        $this->assertAttributeSame('example.com', 'cookieDomain', $persistence);
        $this->assertAttributeSame('/api', 'cookiePath', $persistence);
        $this->assertAttributeSame(true, 'cookieSecure', $persistence);
        $this->assertAttributeSame(true, 'cookieHttpOnly', $persistence);
        $this->assertAttributeSame('nocache', 'cacheLimiter', $persistence);
    }

    public function testInitializeSessionFromRequestReturnsSessionWithEmptyIdentifierAndDataIfNoCookieFound(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Cookie')->willReturn('');
        $request->method('getCookieParams')->willReturn([]);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cachePool->method('getItem')->with('')->willReturn($cacheItem);

        $persistence = new CacheSessionPersistence($this->cachePool, 'test');

        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame('', $session->getId());
        $this->assertSame([], $session->toArray());
    }

    public function testInitializeSessionFromRequestReturnsSessionDataUsingCookieHeaderValue(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Cookie')->willReturn('test=identifier');
        $request->expects($this->never())->method('getCookieParams');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['foo' => 'bar']);
        $this->cachePool->method('getItem')->with('identifier')->willReturn($cacheItem);

        $persistence = new CacheSessionPersistence($this->cachePool, 'test');

        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame('identifier', $session->getId());
        $this->assertSame(['foo' => 'bar'], $session->toArray());
    }

    public function testInitializeSessionFromRequestReturnsSessionDataUsingCookieParamsWhenHeaderNotFound(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Cookie')->willReturn('');
        $request->method('getCookieParams')->willReturn(['test' => 'identifier']);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['foo' => 'bar']);
        $this->cachePool->method('getItem')->with('identifier')->willReturn($cacheItem);

        $persistence = new CacheSessionPersistence($this->cachePool, 'test');

        $session = $persistence->initializeSessionFromRequest($request);

        $this->assertInstanceOf(Session::class, $session);
        $this->assertSame('identifier', $session->getId());
        $this->assertSame(['foo' => 'bar'], $session->toArray());
    }

    public function testPersistSessionWithNoIdentifierAndNoDataReturnsResponseVerbatim(): void
    {
        $session     = new Session([], '');
        $response    = new Response();
        $persistence = new CacheSessionPersistence($this->cachePool, 'test');

        $result = $persistence->persistSession($session, $response);

        $this->cachePool->expects($this->never())->method('getItem');
        $this->cachePool->expects($this->never())->method('save');
        $this->assertSame($response, $result);
    }

    #[DataProvider('validCacheLimiters')]
    public function testPersistSessionWithNoIdentifierAndPopulatedDataPersistsDataAndSetsHeaders(string $cacheLimiter): void
    {
        $session = new Session([], '');
        $session->set('foo', 'bar');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    #[DataProvider('validCacheLimiters')]
    public function testPersistSessionWithIdentifierAndPopulatedDataPersistsDataAndSetsHeaders(string $cacheLimiter): void
    {
        $session     = new Session(['foo' => 'bar'], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('getItem')->with('identifier')->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    #[DataProvider('validCacheLimiters')]
    public function testPersistSessionRequestingRegenerationPersistsDataAndSetsHeaders(string $cacheLimiter): void
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session = $session->regenerate();
        self::assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));

        // This emulates a scenario when the session does not exist in the cache
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool->expects($this->never())->method('deleteItem');

        $this->cachePool
            ->method('getItem')
            ->with($this->callback(static fn(string $value) => $value !== 'identifier'
                && preg_match('/^[a-f0-9]{32}$/', $value)))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    #[DataProvider('validCacheLimiters')]
    public function testPersistSessionRequestingRegenerationRemovesPreviousSession(string $cacheLimiter): void
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session = $session->regenerate();
        self::assertInstanceOf(SessionIdentifierAwareInterface::class, $session);
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));

        // This emulates an existing session existing.
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(true);
        $this->cachePool->expects($this->atLeastOnce())->method('deleteItem')->with('identifier');

        $this->cachePool
            ->method('getItem')
            ->with($this->callback(static fn(string $value) => $value !== 'identifier'
                && preg_match('/^[a-f0-9]{32}$/', $value)))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    #[DataProvider('validCacheLimiters')]
    public function testPersistSessionWithIdentifierAndChangedDataPersistsDataAndSetsHeaders(string $cacheLimiter): void
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session->set('foo', 'baz');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'baz']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));

        // This emulates a scenario when the session does not exist in the cache
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool->expects($this->never())->method('deleteItem');

        $this->cachePool
            ->method('getItem')
            ->with($this->callback(static fn(string $value) => $value !== 'identifier'
                && preg_match('/^[a-f0-9]{32}$/', $value)))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    #[DataProvider('validCacheLimiters')]
    public function testPersistSessionDeletesPreviousSessionIfItExists(string $cacheLimiter): void
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session->set('foo', 'baz');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            $cacheLimiter,
            10800,
            time()
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'baz']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));

        // This emulates an existing session existing.
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(true);
        $this->cachePool->expects($this->atLeastOnce())->method('deleteItem')->with('identifier');

        $this->cachePool
            ->method('getItem')
            ->with($this->callback(static fn(string $value) => $value !== 'identifier'
                && preg_match('/^[a-f0-9]{32}$/', $value)))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @psalm-return iterable<string, array{string}>
     */
    public static function cacheHeaders(): iterable
    {
        foreach (self::CACHE_HEADERS as $header) {
            yield $header => [$header];
        }
    }

    #[DataProvider('cacheHeaders')]
    public function testPersistSessionWithAnyExistingCacheHeadersDoesNotRepopulateCacheHeaders(string $header): void
    {
        $session = new Session([], '');
        $session->set('foo', 'bar');

        $response = new Response();
        $response = $response->withHeader($header, 'some value');

        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test'
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('', $result);
        $this->assertNotCacheHeaders([$header], $result);
    }

    public function testPersistentSessionCookieIncludesExpiration(): void
    {
        $session     = new Session(['foo' => 'bar'], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->atLeastOnce())->method('set')->with(['foo' => 'bar']);
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('getItem')->with('identifier')->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(600, $result);
    }

    public function testPersistenceDurationSpecifiedInSessionUsedWhenPresentEvenWhenEngineDoesNotSpecifyPersistence(): void
    {
        $session  = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();

        // Engine created with defaults, which means no cookie persistence
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test'
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(static fn(array $value) => array_key_exists('foo', $value)
                && $value['foo'] === 'bar'
                && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 1200));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $session->persistSessionFor(1200);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(1200, $result);
    }

    public function testPersistenceDurationSpecifiedInSessionOverridesExpiryWhenSessionPersistenceIsEnabled(): void
    {
        $session     = new Session(['foo' => 'bar'], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(static fn(array $value) => array_key_exists('foo', $value)
                && $value['foo'] === 'bar'
                && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 1200));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $session->persistSessionFor(1200);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(1200, $result);
    }

    public function testPersistenceDurationOfZeroSpecifiedInSessionDisablesPersistence(): void
    {
        $session     = new Session([
            'foo'                                                   => 'bar',
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => 1200,
        ], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test'
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(static fn(array $value) => array_key_exists('foo', $value)
                && $value['foo'] === 'bar'
                && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 0));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $session->persistSessionFor(0);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieHasNoExpiryDirective($result);
    }

    public function testPersistenceDurationOfZeroWithoutSessionLifetimeKeyInDataResultsInGlobalPersistenceExpiry(): void
    {
        // No previous session lifetime set
        $session     = new Session([
            'foo' => 'bar',
        ], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(static fn(array $value) => array_key_exists('foo', $value)
                && $value['foo'] === 'bar'
                && ! array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool->method('getItem')->with('identifier')->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertSame(0, $session->getSessionLifetime());
        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(600, $result);
    }

    public function testPersistenceDurationOfZeroIgnoresGlobalPersistenceExpiry(): void
    {
        $session     = new Session([
            'foo' => 'bar',
        ], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(static fn(array $value) => array_key_exists('foo', $value)
                && $value['foo'] === 'bar'
                && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 0));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        // Calling persistSessionFor sets the session lifetime key in the data,
        // which allows us to override the value.
        $session->persistSessionFor(0);
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieHasNoExpiryDirective($result);
    }

    public function testPersistenceDurationInSessionDataWithValueOfZeroIgnoresGlobalPersistenceExpiry(): void
    {
        $session     = new Session([
            'foo'                                                   => 'bar',
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => 0,
        ], 'identifier');
        $response    = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
            '/',
            'nocache',
            600, // expiry
            time(),
            true // mark session cookie as persistent
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(static fn(array $value) => array_key_exists('foo', $value)
                && $value['foo'] === 'baz'
                && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 0));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool
            ->method('getItem')
            ->with($this->matchesRegularExpression('/^[a-f0-9]{32}$/'))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        // Changing the data, to ensure we trigger a new session cookie
        $session->set('foo', 'baz');
        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertCookieHasNoExpiryDirective($result);
    }

    public function testInitializeIdReturnsSessionWithId(): void
    {
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
        );
        $session     = new Session(['foo' => 'bar']);
        $actual      = $persistence->initializeId($session);

        $this->assertNotSame($session, $actual);
        $this->assertNotEmpty($actual->getId());
        $this->assertSame(['foo' => 'bar'], $actual->toArray());
    }

    public function testInitializeIdRegeneratesSessionId(): void
    {
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
        );
        $session     = new Session(['foo' => 'bar'], 'original-id');
        $session     = $session->regenerate();
        $actual      = $persistence->initializeId($session);

        $this->assertNotEmpty($actual->getId());
        $this->assertNotSame('original-id', $actual->getId());
        $this->assertFalse($actual->isRegenerated());
    }

    public function testInitializeIdReturnsSessionUnaltered(): void
    {
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test',
        );
        $session     = new Session(['foo' => 'bar'], 'original-id');
        $actual      = $persistence->initializeId($session);

        $this->assertSame($session, $actual);
    }
}
