<?php

declare(strict_types=1);

namespace MezzioTest\Session\Cache\Asset;

use Laminas\Diactoros\Response\TextResponse;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function assert;

final class TestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;
    public ResponseInterface $response;
    private ?string $sessionVariable = null;
    private ?string $sessionValue    = null;

    public function __construct(?ResponseInterface $response = null)
    {
        $this->response = $response ?? new TextResponse('Foo');
    }

    public function setSessionVariable(string $name, string $value): void
    {
        $this->sessionVariable = $name;
        $this->sessionValue    = $value;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        if ($this->sessionVariable !== null && $this->sessionValue !== null) {
            $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
            assert($session instanceof SessionInterface);
            $session->set($this->sessionVariable, $this->sessionValue);
        }

        return $this->response;
    }

    public function receivedRequest(): ServerRequestInterface
    {
        if (! $this->request) {
            throw new RuntimeException('No request has been received');
        }

        return $this->request;
    }

    public function requestWasReceived(): bool
    {
        return $this->request !== null;
    }
}
