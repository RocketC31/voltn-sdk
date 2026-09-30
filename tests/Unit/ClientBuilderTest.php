<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\Auth\InMemoryTokenStorage;
use RocketC31\Voltn\ClientBuilder;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class ClientBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseUriProvider(): iterable
    {
        yield 'missing scheme' => ['tenant.example.test'];
        yield 'has a path' => ['https://tenant.example.test/some/path'];
        yield 'has a query string' => ['https://tenant.example.test?foo=bar'];
        yield 'has a fragment' => ['https://tenant.example.test#frag'];
        yield 'unsupported scheme' => ['ftp://tenant.example.test'];
        yield 'not a url at all' => ['not a url'];
    }

    #[DataProvider('invalidBaseUriProvider')]
    public function testCreateRejectsInvalidBaseUri(string $baseUri): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientBuilder::create($baseUri);
    }

    public function testCreateAcceptsHostOnlyBaseUri(): void
    {
        $builder = ClientBuilder::create('https://tenant.example.test');
        self::assertInstanceOf(ClientBuilder::class, $builder);
    }

    public function testCreateAcceptsBaseUriWithTrailingSlash(): void
    {
        $builder = ClientBuilder::create('https://tenant.example.test/');
        self::assertInstanceOf(ClientBuilder::class, $builder);
    }

    public function testBuildWithClientCredentialsObtainsTokenTransparentlyOnFirstRequest(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['access_token' => 'cc-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        $factory->queueJson(200, ['id' => 1, 'name' => 'Invoices']);

        $client = ClientBuilder::create('https://tenant.example.test')
            ->withHttpClient($factory->getClient())
            ->withRequestFactory($factory->getRequestFactory())
            ->withStreamFactory($factory->getStreamFactory())
            ->withClientCredentials('client-id', 'client-secret')
            ->build();

        $folder = $client->folders()->get(1);

        self::assertSame(1, $folder->getId());
        self::assertSame(2, $factory->count());

        $requests = $factory->getRequestHistory();
        self::assertSame(
            'https://tenant.example.test/oauth2/token',
            (string) $requests[0]->getUri(),
        );
        self::assertSame(
            'https://tenant.example.test/api/folder/1',
            (string) $requests[1]->getUri(),
        );
        self::assertSame('Bearer cc-token', $requests[1]->getHeaderLine('Authorization'));
        self::assertSame('cc-token', $client->getCurrentAccessToken()?->getAccessToken());
    }

    public function testBuildWithManualAccessTokenSkipsOAuth2Entirely(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['id' => 1, 'name' => 'Invoices']);

        $token = AccessToken::fromTokenResponse(['access_token' => 'manual-token', 'expires_in' => 3600]);

        $client = ClientBuilder::create('https://tenant.example.test')
            ->withHttpClient($factory->getClient())
            ->withRequestFactory($factory->getRequestFactory())
            ->withStreamFactory($factory->getStreamFactory())
            ->withAccessToken($token)
            ->build();

        $client->folders()->get(1);

        self::assertSame(1, $factory->count());
        self::assertSame('Bearer manual-token', $factory->getLastRequest()->getHeaderLine('Authorization'));
        self::assertNull($client->oauth2());
    }

    public function testBuildWithOAuth2AndTokenStorageRefreshesOnExpiry(): void
    {
        $factory = new MockTransportFactory();
        // Refresh must happen before the folder call, so it must be queued first.
        $factory->queueJson(200, ['access_token' => 'new', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]);
        $factory->queueJson(200, ['id' => 1]);

        $almostExpired = AccessToken::fromTokenResponse([
            'access_token' => 'old',
            'refresh_token' => 'refresh-token',
            'expires_in' => 5,
        ]);
        $storage = new InMemoryTokenStorage($almostExpired);

        $client = ClientBuilder::create('https://tenant.example.test')
            ->withHttpClient($factory->getClient())
            ->withRequestFactory($factory->getRequestFactory())
            ->withStreamFactory($factory->getStreamFactory())
            ->withOAuth2('client-id')
            ->withTokenStorage($storage)
            ->build();

        $client->folders()->get(1);

        $requests = $factory->getRequestHistory();
        self::assertSame('https://tenant.example.test/oauth2/token', (string) $requests[0]->getUri());
        self::assertSame('Bearer new', $requests[1]->getHeaderLine('Authorization'));
        self::assertSame('new', $storage->get()?->getAccessToken());
    }

    public function testWithImpersonationSetsDefaultAsHeader(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['id' => 1]);

        $token = AccessToken::fromTokenResponse(['access_token' => 'abc']);

        $client = ClientBuilder::create('https://tenant.example.test')
            ->withHttpClient($factory->getClient())
            ->withRequestFactory($factory->getRequestFactory())
            ->withStreamFactory($factory->getStreamFactory())
            ->withAccessToken($token)
            ->withImpersonation(42)
            ->build();

        $client->folders()->get(1);

        self::assertSame('42', $factory->getLastRequest()->getHeaderLine('As'));
    }
}
