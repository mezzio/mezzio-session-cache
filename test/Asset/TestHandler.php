<?php

declare(strict_types=1);

namespace MezzioTest\Session\Cache\Asset;

use Laminas\Diactoros\Response\TextResponse;
use Mezzio\Session\RetrieveSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class TestHandler implements RequestHandlerInterface
{
    /** @var ServerRequestInterface|null */
    public $request;
    /** @var ResponseInterface */
    public $response;
    /** @var string|null */
    private $sessionVariable;
    /** @var string|null */
    private $sessionValue;

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
            $session = RetrieveSession::fromRequest($request);
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
