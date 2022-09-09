<?php

declare(strict_types=1);

namespace MezzioTest\Session\Cache;

use Dflydev\FigCookies\SetCookies;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\SessionMiddleware;
use MezzioTest\Session\Cache\Asset\TestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CacheSessionPersistenceIntegrationTest extends TestCase
{
    /** @var CacheItemPoolInterface */
    private $cache;
    /** @var CacheSessionPersistence */
    private $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache   = new ArrayAdapter();
        $this->storage = new CacheSessionPersistence(
            $this->cache,
            'Session',
        );
    }

    public function testThatANewAndEmptySessionWillNotCauseASetCookieResponse(): void
    {
        $request    = new ServerRequest();
        $middleware = new SessionMiddleware($this->storage);
        $handler    = new TestHandler();

        $response = $middleware->process($request, $handler);

        self::assertEquals('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame($handler->response, $response);
    }

    public function testThatAModifiedEmptySessionWillCauseASetCookieHeader(): void
    {
        $request    = new ServerRequest();
        $middleware = new SessionMiddleware($this->storage);
        $handler    = new TestHandler();
        $handler->setSessionVariable('something', 'groovy');
        $response = $middleware->process($request, $handler);

        $setCookie = SetCookies::fromResponse($response);
        $cookie    = $setCookie->get('Session');
        self::assertNotNull($cookie);

        $id = $cookie->getValue();
        self::assertNotNull($id);

        $item = $this->cache->getItem($id);
        self::assertTrue($item->isHit());
        self::assertNotNull($item->get());

        self::assertNotSame($handler->response, $response);
    }

    public function testThatAnUnchangedSessionWillASetCookieHeaderOnEveryRequest(): void
    {
        $item = $this->cache->getItem('foo');
        $item->set(['foo' => 'bar']);
        $this->cache->save($item);

        $request    = (new ServerRequest())->withHeader('Cookie', 'Session=foo;');
        $middleware = new SessionMiddleware($this->storage);
        $handler    = new TestHandler();
        $response   = $middleware->process($request, $handler);

        $setCookie = SetCookies::fromResponse($response);
        $cookie    = $setCookie->get('Session');
        self::assertNotNull($cookie);

        $id = $cookie->getValue();
        self::assertEquals('foo', $id);

        self::assertNotSame($handler->response, $response);
    }

    public function testThatAChangedSessionWillCauseRegenerationAndASetCookieHeader(): void
    {
        $item = $this->cache->getItem('foo');
        $item->set(['foo' => 'bar']);
        $this->cache->save($item);

        $request    = (new ServerRequest())->withHeader('Cookie', 'Session=foo;');
        $middleware = new SessionMiddleware($this->storage);
        $handler    = new TestHandler();
        $handler->setSessionVariable('something', 'groovy');
        $response = $middleware->process($request, $handler);

        $setCookie = SetCookies::fromResponse($response);
        $cookie    = $setCookie->get('Session');
        self::assertNotNull($cookie);

        $id = $cookie->getValue();
        self::assertNotNull($id);

        self::assertNotSame('foo', $id);
        self::assertNotSame($handler->response, $response);

        $item  = $this->cache->getItem('foo');
        $value = $item->get();
        self::assertNull($value, 'The previous session data should have been deleted');
    }
}
