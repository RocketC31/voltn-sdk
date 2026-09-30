<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Auth\OAuth2Client;
use RocketC31\Voltn\Auth\Pkce;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class OAuth2ClientTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/OAuth2';

    private function makeOAuth2Client(MockTransportFactory $factory, string $clientId = 'client-123'): OAuth2Client
    {
        $transport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test',
        );

        return new OAuth2Client($transport, $clientId);
    }

    public function testClientCredentialsReturnsAccessToken(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/token_client_credentials.json');

        $client = $this->makeOAuth2Client($factory);
        $token = $client->clientCredentials('super-secret');

        self::assertSame('cc-access-token', $token->getAccessToken());
        self::assertNull($token->getRefreshToken());
        self::assertFalse($token->canRefresh());
        self::assertNotNull($token->getExpiresAt());
    }

    public function testClientCredentialsSendsExpectedBody(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/token_client_credentials.json');

        $client = $this->makeOAuth2Client($factory, 'client-123');
        $client->clientCredentials('super-secret');

        $request = $factory->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://tenant.example.test/oauth2/token', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string) $request->getBody(), true);
        self::assertSame([
            'client_id' => 'client-123',
            'grant_type' => 'client_credentials',
            'client_secret' => 'super-secret',
        ], $body);
    }

    public function testClientCredentialsPropagatesErrorAsMappedException(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'unknown client']);

        $client = $this->makeOAuth2Client($factory);

        $this->expectException(NotFoundException::class);
        $client->clientCredentials('wrong-secret');
    }

    public function testRefreshReturnsNewAccessToken(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/token_refresh.json');

        $client = $this->makeOAuth2Client($factory);
        $token = $client->refresh('old-refresh-token');

        self::assertSame('refreshed-access-token', $token->getAccessToken());
        self::assertSame('new-refresh-token', $token->getRefreshToken());

        $request = $factory->getLastRequest();
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('refresh_token', $body['grant_type']);
        self::assertSame('old-refresh-token', $body['refresh_token']);
        self::assertArrayNotHasKey('client_secret', $body);
    }

    public function testRefreshIncludesClientSecretWhenProvided(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/token_refresh.json');

        $client = $this->makeOAuth2Client($factory);
        $client->refresh('old-refresh-token', 'secret');

        $request = $factory->getLastRequest();
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('secret', $body['client_secret']);
    }

    public function testExchangeAuthorizationCodeWithClientSecret(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/token_authorization_code.json');

        $client = $this->makeOAuth2Client($factory);
        $token = $client->exchangeAuthorizationCode('auth-code', 'https://app.example.test/callback', clientSecret: 'secret');

        self::assertSame('ac-access-token', $token->getAccessToken());

        $request = $factory->getLastRequest();
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame('authorization_code', $body['grant_type']);
        self::assertSame('auth-code', $body['code']);
        self::assertSame('https://app.example.test/callback', $body['redirect_uri']);
        self::assertSame('secret', $body['client_secret']);
        self::assertArrayNotHasKey('code_verifier', $body);
    }

    public function testExchangeAuthorizationCodeWithPkceCodeVerifier(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/token_authorization_code.json');

        $client = $this->makeOAuth2Client($factory);
        $pair = Pkce::generate();
        $client->exchangeAuthorizationCode('auth-code', 'https://app.example.test/callback', codeVerifier: $pair->codeVerifier);

        $request = $factory->getLastRequest();
        $body = json_decode((string) $request->getBody(), true);
        self::assertSame($pair->codeVerifier, $body['code_verifier']);
        self::assertArrayNotHasKey('client_secret', $body);
    }

    public function testExchangeAuthorizationCodeRequiresSecretOrVerifier(): void
    {
        $factory = new MockTransportFactory();
        $client = $this->makeOAuth2Client($factory);

        $this->expectException(InvalidArgumentException::class);
        $client->exchangeAuthorizationCode('auth-code', 'https://app.example.test/callback');
    }

    public function testGetAuthorizationUrlBuildsExpectedUrl(): void
    {
        $factory = new MockTransportFactory();
        $client = $this->makeOAuth2Client($factory, 'client-123');

        $url = $client->getAuthorizationUrl('https://app.example.test/callback', state: 'xyz');

        self::assertSame(
            'https://tenant.example.test/oauth2/authorize?client_id=client-123&response_type=code&scope=all&redirect_uri=https%3A%2F%2Fapp.example.test%2Fcallback&state=xyz',
            $url,
        );
    }

    public function testGetAuthorizationUrlIncludesPkceParameters(): void
    {
        $factory = new MockTransportFactory();
        $client = $this->makeOAuth2Client($factory, 'client-123');
        $pair = Pkce::generate();

        $url = $client->getAuthorizationUrl('https://app.example.test/callback', pkce: $pair);

        self::assertStringContainsString('code_challenge=' . rawurlencode($pair->codeChallenge), $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function testGetAuthorizationUrlDoesNotDispatchAnyRequest(): void
    {
        $factory = new MockTransportFactory();
        $client = $this->makeOAuth2Client($factory);

        $client->getAuthorizationUrl('https://app.example.test/callback');

        self::assertSame(0, $factory->count());
    }
}
