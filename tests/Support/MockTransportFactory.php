<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Test helper building a PSR-18-compliant HTTP client backed by Guzzle's
 * {@see MockHandler}, so the SDK's real PSR-18/PSR-17 code paths are
 * exercised without ever hitting the live Voltn API.
 */
final class MockTransportFactory
{
    private MockHandler $mockHandler;

    private Client $client;

    /** @var list<array{request: RequestInterface, response: ResponseInterface|null}> */
    private array $history = [];

    private HttpFactory $psr17Factory;

    public function __construct()
    {
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $handlerStack->push(Middleware::history($this->history));

        $this->client = new Client(['handler' => $handlerStack]);
        $this->psr17Factory = new HttpFactory();
    }

    public function queueResponse(int $status = 200, string $body = '', array $headers = []): void
    {
        $this->mockHandler->append(new Response($status, $headers, $body));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function queueJson(int $status, array $data, array $headers = []): void
    {
        $headers['Content-Type'] ??= 'application/json';
        $this->mockHandler->append(new Response($status, $headers, json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function queueJsonFixture(string $fixturePath, int $status = 200, array $headers = []): void
    {
        $headers['Content-Type'] ??= 'application/json';
        $body = file_get_contents($fixturePath);

        if ($body === false) {
            throw new \RuntimeException(sprintf('Could not read fixture "%s".', $fixturePath));
        }

        $this->mockHandler->append(new Response($status, $headers, $body));
    }

    public function queueException(Throwable $exception): void
    {
        $this->mockHandler->append($exception);
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getRequestFactory(): HttpFactory
    {
        return $this->psr17Factory;
    }

    public function getStreamFactory(): HttpFactory
    {
        return $this->psr17Factory;
    }

    /**
     * @return list<RequestInterface>
     */
    public function getRequestHistory(): array
    {
        return array_map(static fn (array $entry) => $entry['request'], $this->history);
    }

    public function getLastRequest(): ?RequestInterface
    {
        $history = $this->getRequestHistory();

        return $history === [] ? null : $history[array_key_last($history)];
    }

    public function count(): int
    {
        return count($this->history);
    }
}
