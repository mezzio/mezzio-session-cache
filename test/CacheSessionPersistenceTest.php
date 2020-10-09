<?php

/**
 * @see       https://github.com/mezzio/mezzio-session-cache for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-session-cache/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-session-cache/blob/master/LICENSE.md New BSD License
 */

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
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;

class CacheSessionPersistenceTest extends TestCase
{
    const GMDATE_REGEXP = '/[a-z]{3}, \d+ [a-z]{3} \d{4} \d{2}:\d{2}:\d{2} \w+$/i';

    const CACHE_HEADERS = [
        'cache-control',
        'expires',
        'last-modified',
        'pragma',
    ];

    public function setUp(): void
    {
        $this->cachePool = $this->createMock(CacheItemPoolInterface::class);
        $this->currentTime = new DateTimeImmutable();
    }

    /** @param mixed $expected */
    private function assertAttributeSame($expected, string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        $r->setAccessible(true);
        $this->assertSame($expected, $r->getValue($instance));
    }

    /** @param mixed $expected */
    private function assertAttributeNotEmpty(string $property, object $instance): void
    {
        $r = new ReflectionProperty($instance, $property);
        $r->setAccessible(true);
        $this->assertNotEmpty($r->getValue($instance));
    }

    public function assertSetCookieUsesIdentifier(string $identifier, Response $response)
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

    public function assertSetCookieUsesNewIdentifier(string $identifier, Response $response)
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

    public function assertCookieExpiryMirrorsExpiry(int $expiry, Response $response)
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $parts = explode(';', $setCookie);
        $parts = array_map(function ($value) {
            return trim($value);
        }, $parts);
        $parts = array_filter($parts, function ($value) {
            return (bool) preg_match('/^Expires=/', $value);
        });

        $this->assertSame(1, count($parts), 'No Expires directive found in cookie: ' . $setCookie);

        $compare = $this->currentTime->add(new DateInterval(sprintf('PT%dS', $expiry)));

        $value = array_shift($parts);
        [, $expires] = explode('=', $value);
        $expiresDate = new DateTimeImmutable($expires);

        $this->assertGreaterThanOrEqual(
            $expiresDate,
            $compare,
            sprintf('Cookie expiry "%s" is not at least "%s"', $expiresDate->format('r'), $compare->format('r'))
        );
    }

    public function assertCookieHasNoExpiryDirective(Response $response)
    {
        $setCookie = $response->getHeaderLine('Set-Cookie');
        $parts = explode(';', $setCookie);
        $parts = array_map(function ($value) {
            return trim($value);
        }, $parts);
        $parts = array_filter($parts, function ($value) {
            return (bool) preg_match('/^Expires=/', $value);
        });

        $this->assertSame(
            0,
            count($parts),
            'Expires directive found in cookie, but should not be present: ' . $setCookie
        );
    }

    public function assertCacheHeaders(string $cacheLimiter, Response $response)
    {
        switch ($cacheLimiter) {
            case 'nocache':
                return $this->assertNoCache($response);
            case 'public':
                return $this->assertCachePublic($response);
            case 'private':
                return $this->assertCachePrivate($response);
            case 'private_no_expire':
                return $this->assertCachePrivateNoExpire($response);
        }
    }

    public function assertNotCacheHeaders(array $allowed, Response $response)
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

    public function assertNoCache(Response $response)
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

    public function assertCachePublic(Response $response)
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

    public function assertCachePrivate(Response $response)
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

    public function assertCachePrivateNoExpire(Response $response)
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

    public function testConstructorRaisesExceptionForEmptyCookieName()
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new CacheSessionPersistence($this->cachePool, '');
    }

    public function testConstructorUsesDefaultsForOptionalArguments()
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

    public function validCacheLimiters() : array
    {
        return [
            'nocache'           => ['nocache'],
            'public'            => ['public'],
            'private'           => ['private'],
            'private_no_expire' => ['private_no_expire'],
        ];
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testConstructorAllowsProvidingAllArguments($cacheLimiter)
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

    public function testDefaultsToNocacheIfInvalidCacheLimiterProvided()
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

    public function testInitializeSessionFromRequestReturnsSessionWithEmptyIdentifierAndDataIfNoCookieFound()
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

    public function testInitializeSessionFromRequestReturnsSessionDataUsingCookieHeaderValue()
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

    public function testInitializeSessionFromRequestReturnsSessionDataUsingCookieParamsWhenHeaderNotFound()
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

    public function testPersistSessionWithNoIdentifierAndNoDataReturnsResponseVerbatim()
    {
        $session = new Session([], '');
        $response = new Response();
        $persistence = new CacheSessionPersistence($this->cachePool, 'test');

        $result = $persistence->persistSession($session, $response);

        $this->cachePool->expects($this->never())->method('getItem');
        $this->cachePool->expects($this->never())->method('save');
        $this->assertSame($response, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionWithNoIdentifierAndPopulatedDataPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session([], '');
        $session->set('foo', 'bar');
        $response = new Response();
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

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionWithIdentifierAndPopulatedDataPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();
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

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionRequestingRegenerationPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session = $session->regenerate();
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return $value !== 'identifier'
                    && preg_match('/^[a-f0-9]{32}$/', $value);
            }))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionRequestingRegenerationRemovesPreviousSession(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session = $session->regenerate();
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return $value !== 'identifier'
                    && preg_match('/^[a-f0-9]{32}$/', $value);
            }))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionWithIdentifierAndChangedDataPersistsDataAndSetsHeaders(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session->set('foo', 'baz');
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return $value !== 'identifier'
                    && preg_match('/^[a-f0-9]{32}$/', $value);
            }))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    /**
     * @dataProvider validCacheLimiters
     */
    public function testPersistSessionDeletesPreviousSessionIfItExists(string $cacheLimiter)
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $session->set('foo', 'baz');
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return $value !== 'identifier'
                    && preg_match('/^[a-f0-9]{32}$/', $value);
            }))
            ->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertNotSame($response, $result);
        $this->assertSetCookieUsesNewIdentifier('identifier', $result);
        $this->assertCacheHeaders($cacheLimiter, $result);
    }

    public function cacheHeaders() : iterable
    {
        foreach (self::CACHE_HEADERS as $header) {
            yield $header => [$header];
        }
    }

    /**
     * @dataProvider cacheHeaders
     */
    public function testPersistSessionWithAnyExistingCacheHeadersDoesNotRepopulateCacheHeaders(string $header)
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

    public function testPersistentSessionCookieIncludesExpiration()
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();
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

    public function testPersistenceDurationSpecifiedInSessionUsedWhenPresentEvenWhenEngineDoesNotSpecifyPersistence()
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
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
            ->with($this->callback(function ($value) {
                return is_array($value)
                    && array_key_exists('foo', $value)
                    && $value['foo'] === 'bar'
                    && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                    && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 1200;
            }));
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

    public function testPersistenceDurationSpecifiedInSessionOverridesExpiryWhenSessionPersistenceIsEnabled()
    {
        $session = new Session(['foo' => 'bar'], 'identifier');
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return is_array($value)
                    && array_key_exists('foo', $value)
                    && $value['foo'] === 'bar'
                    && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                    && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 1200;
            }));
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

    public function testPersistenceDurationOfZeroSpecifiedInSessionDisablesPersistence()
    {
        $session = new Session([
            'foo' => 'bar',
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => 1200,
        ], 'identifier');
        $response = new Response();
        $persistence = new CacheSessionPersistence(
            $this->cachePool,
            'test'
        );

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->atLeastOnce())
            ->method('set')
            ->with($this->callback(function ($value) {
                return is_array($value)
                    && array_key_exists('foo', $value)
                    && $value['foo'] === 'bar'
                    && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                    && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 0;
            }));
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

    public function testPersistenceDurationOfZeroWithoutSessionLifetimeKeyInDataResultsInGlobalPersistenceExpiry()
    {
        // No previous session lifetime set
        $session = new Session([
            'foo' => 'bar',
        ], 'identifier');
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return is_array($value)
                    && array_key_exists('foo', $value)
                    && $value['foo'] === 'bar'
                    && ! array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value);
            }));
        $cacheItem->expects($this->atLeastOnce())->method('expiresAfter')->with($this->isType('int'));
        $this->cachePool->method('hasItem')->with('identifier')->willReturn(false);
        $this->cachePool->method('getItem')->with('identifier')->willReturn($cacheItem);
        $this->cachePool->expects($this->atLeastOnce())->method('save')->with($cacheItem);

        $result = $persistence->persistSession($session, $response);

        $this->assertSame(0, $session->getSessionLifetime());
        $this->assertNotSame($response, $result);
        $this->assertCookieExpiryMirrorsExpiry(600, $result);
    }

    public function testPersistenceDurationOfZeroIgnoresGlobalPersistenceExpiry()
    {
        $session = new Session([
            'foo' => 'bar',
        ], 'identifier');
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return is_array($value)
                    && array_key_exists('foo', $value)
                    && $value['foo'] === 'bar'
                    && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                    && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 0;
            }));
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

    public function testPersistenceDurationInSessionDataWithValueOfZeroIgnoresGlobalPersistenceExpiry()
    {
        $session = new Session([
            'foo' => 'bar',
            SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY => 0,
        ], 'identifier');
        $response = new Response();
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
            ->with($this->callback(function ($value) {
                return is_array($value)
                    && array_key_exists('foo', $value)
                    && $value['foo'] === 'baz'
                    && array_key_exists(SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY, $value)
                    && $value[SessionCookiePersistenceInterface::SESSION_LIFETIME_KEY] === 0;
            }));
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
}
