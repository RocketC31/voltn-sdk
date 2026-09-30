<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Auth;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\Auth\AuthenticationMiddleware;
use RocketC31\Voltn\Auth\InMemoryTokenStorage;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;
use RocketC31\Voltn\Tests\Support\NonSeekableStream;

final class AuthenticationMiddlewareTest extends TestCase
{
    private function makeRequest(MockTransportFactory $factory, string $body = ''): RequestInterface
    {
        $request = $factory->getRequestFactory()->createRequest('GET', 'https://tenant.example.test/api/folders');

        if ($body !== '') {
            $request = $request->withBody($factory->getStreamFactory()->createStream($body));
        }

        return $request;
    }

    private function token(string $accessToken, int $expiresIn = 3600, ?string $refreshToken = null): AccessToken
    {
        return AccessToken::fromTokenResponse([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
        ]);
    }

    public function testAttachesAuthorizationHeaderFromCurrentToken(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('abc'));
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame('Bearer abc', $factory->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testAttachesImpersonationHeaderWhenConfigured(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('abc'), impersonateAs: '99');
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame('99', $factory->getLastRequest()->getHeaderLine('As'));
    }

    public function testDoesNotOverrideExplicitAsHeaderOnRequest(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('abc'), impersonateAs: '99');
        $request = $this->makeRequest($factory)->withHeader('As', '7');
        $middleware->sendRequest($request);

        self::assertSame('7', $factory->getLastRequest()->getHeaderLine('As'));
    }

    public function testFetchesTokenLazilyWhenNoInitialTokenAndRefresherConfigured(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $calls = 0;
        $refresher = function () use (&$calls) {
            ++$calls;

            return $this->token('fresh');
        };

        $middleware = new AuthenticationMiddleware($factory->getClient(), null, Closure::fromCallable($refresher));
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(1, $calls);
        self::assertSame('Bearer fresh', $factory->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testProactivelyRefreshesTokenNearExpiry(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        // Expires in 10s, well within the middleware's 30s leeway.
        $almostExpired = $this->token('old', 10);

        $calls = 0;
        $refresher = function () use (&$calls) {
            ++$calls;

            return $this->token('new');
        };

        $middleware = new AuthenticationMiddleware($factory->getClient(), $almostExpired, Closure::fromCallable($refresher));
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(1, $calls);
        self::assertSame('Bearer new', $factory->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testDoesNotRefreshWhenTokenIsFarFromExpiry(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $freshToken = $this->token('old', 3600);

        $calls = 0;
        $refresher = function () use (&$calls) {
            ++$calls;

            return $this->token('new');
        };

        $middleware = new AuthenticationMiddleware($factory->getClient(), $freshToken, Closure::fromCallable($refresher));
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(0, $calls);
        self::assertSame('Bearer old', $factory->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testNeverExpiringTokenIsNotProactivelyRefreshed(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $nonExpiring = AccessToken::fromTokenResponse(['access_token' => 'offline-token']);

        $calls = 0;
        $refresher = function () use (&$calls) {
            ++$calls;

            return $this->token('new');
        };

        $middleware = new AuthenticationMiddleware($factory->getClient(), $nonExpiring, Closure::fromCallable($refresher));
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(0, $calls);
    }

    public function testRetriesOnceOn401WithRefreshedToken(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(401, '{"message":"expired"}');
        $factory->queueResponse(200, '{"ok":true}');

        $calls = 0;
        $refresher = function () use (&$calls) {
            ++$calls;

            return $this->token('refreshed-' . $calls);
        };

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('old'), Closure::fromCallable($refresher));
        $response = $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $calls);
        self::assertSame(2, $factory->count());

        $requests = $factory->getRequestHistory();
        self::assertSame('Bearer old', $requests[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer refreshed-1', $requests[1]->getHeaderLine('Authorization'));
    }

    public function testDoesNotRetryMoreThanOnceWhenSecondAttemptAlsoReturns401(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(401, '{"message":"expired"}');
        $factory->queueResponse(401, '{"message":"still invalid"}');

        $refresher = fn () => $this->token('refreshed');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('old'), Closure::fromCallable($refresher));
        $response = $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(2, $factory->count());
    }

    public function testDoesNotRetryInFullyManualModeWithoutRefresher(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(401, '{"message":"expired"}');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('old'));
        $response = $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $factory->count());
    }

    public function testPersistsRefreshedTokenInTokenStorage(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(401, '{}');
        $factory->queueResponse(200, '{}');

        $storage = new InMemoryTokenStorage($this->token('old'));
        $refresher = fn () => $this->token('refreshed', 3600, 'new-refresh');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('old'), Closure::fromCallable($refresher), $storage);
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame('refreshed', $storage->get()?->getAccessToken());
    }

    public function testProactiveRefreshAlsoPersistsToStorage(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $storage = new InMemoryTokenStorage();
        $almostExpired = $this->token('old', 5);
        $refresher = fn () => $this->token('new', 3600);

        $middleware = new AuthenticationMiddleware($factory->getClient(), $almostExpired, Closure::fromCallable($refresher), $storage);
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame('new', $storage->get()?->getAccessToken());
    }

    public function testLoadsInitialTokenFromStorageWhenNoneProvidedExplicitly(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '{}');

        $storage = new InMemoryTokenStorage($this->token('from-storage'));

        $middleware = new AuthenticationMiddleware($factory->getClient(), null, null, $storage);
        $middleware->sendRequest($this->makeRequest($factory));

        self::assertSame('Bearer from-storage', $factory->getLastRequest()->getHeaderLine('Authorization'));
    }

    public function testSkipsRetryWhenRequestBodyIsNonSeekableStream(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(401, '{}');

        $refresher = fn () => $this->token('refreshed');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $this->token('old'), Closure::fromCallable($refresher));

        $request = $this->makeRequest($factory)->withBody(new NonSeekableStream('some upload bytes'));
        $response = $middleware->sendRequest($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $factory->count());
    }

    public function testGetCurrentTokenReturnsTheTokenInUseWithoutTriggeringRefresh(): void
    {
        $factory = new MockTransportFactory();
        $token = $this->token('abc');

        $middleware = new AuthenticationMiddleware($factory->getClient(), $token);

        self::assertSame($token, $middleware->getCurrentToken());
        self::assertSame(0, $factory->count());
    }
}
