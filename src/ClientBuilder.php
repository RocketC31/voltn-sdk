<?php

declare(strict_types=1);

namespace RocketC31\Voltn;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\Auth\AuthenticationMiddleware;
use RocketC31\Voltn\Auth\OAuth2Client;
use RocketC31\Voltn\Auth\TokenStorage;
use RocketC31\Voltn\Exception\AuthenticationException;
use RocketC31\Voltn\Http\HttpTransport;

/**
 * Builds a {@see Client}.
 *
 * Only the tenant base URI is required (e.g. `https://tenant.voltn.example`,
 * host only — the SDK appends `/api` itself for regular resource calls, and
 * talks to the OAuth2 endpoints directly at the tenant root). Everything
 * else is optional:
 *
 * - A PSR-18 client and PSR-17 factories are auto-discovered via
 *   `php-http/discovery` if not supplied.
 * - Auth usage mode is picked based on what's configured:
 *   - {@see self::withClientCredentials()} alone -> fire-and-forget
 *     `client_credentials`, refreshed transparently forever, no
 *     {@see TokenStorage} required.
 *   - {@see self::withOAuth2()} + {@see self::withTokenStorage()} (and,
 *     usually, an initial token already containing a refresh token) ->
 *     full interactive flow, refreshed tokens are persisted back through
 *     the storage.
 *   - {@see self::withAccessToken()} alone -> fully manual, no refresh
 *     logic is engaged.
 */
final class ClientBuilder
{
    private ?ClientInterface $httpClient = null;

    private ?RequestFactoryInterface $requestFactory = null;

    private ?StreamFactoryInterface $streamFactory = null;

    private ?TokenStorage $tokenStorage = null;

    private ?AccessToken $accessToken = null;

    private ?string $clientId = null;

    private ?string $clientSecret = null;

    private bool $useClientCredentials = false;

    private ?string $impersonateAs = null;

    private function __construct(private readonly string $baseUri)
    {
    }

    /**
     * @param string $baseUri the tenant host, e.g. `https://tenant.voltn.example`
     *                        (no path, query, or fragment)
     */
    public static function create(string $baseUri): self
    {
        return new self(self::validateBaseUri($baseUri));
    }

    public function withHttpClient(ClientInterface $httpClient): self
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    public function withRequestFactory(RequestFactoryInterface $requestFactory): self
    {
        $this->requestFactory = $requestFactory;

        return $this;
    }

    public function withStreamFactory(StreamFactoryInterface $streamFactory): self
    {
        $this->streamFactory = $streamFactory;

        return $this;
    }

    /**
     * Configure a place to persist tokens across requests. Required for the
     * full interactive OAuth2 flow; optional (but harmless) for
     * client_credentials.
     */
    public function withTokenStorage(TokenStorage $tokenStorage): self
    {
        $this->tokenStorage = $tokenStorage;

        return $this;
    }

    /**
     * Fully manual usage mode: the caller manages the {@see AccessToken}
     * (obtained however it likes, e.g. via {@see OAuth2Client} called
     * directly) and injects it here. No automatic refresh or retry-on-401
     * is engaged — a 401 propagates as an
     * {@see \RocketC31\Voltn\Exception\AuthenticationException}.
     */
    public function withAccessToken(AccessToken $accessToken): self
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Enables the OAuth2 client (`Client::oauth2()`) and, combined with
     * {@see self::withTokenStorage()} and an initial token that carries a
     * refresh token, the full interactive-flow auto-refresh usage mode.
     */
    public function withOAuth2(string $clientId, ?string $clientSecret = null): self
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;

        return $this;
    }

    /**
     * Fire-and-forget `client_credentials` usage mode: the SDK transparently
     * obtains and refreshes access tokens on its own, no
     * {@see TokenStorage} required. This is the mode a backend integration
     * with no end user in the loop (e.g. a scheduled backup job) will
     * typically use.
     */
    public function withClientCredentials(string $clientId, string $clientSecret): self
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->useClientCredentials = true;

        return $this;
    }

    /**
     * Set a default `As: <userId>` impersonation header applied to every
     * request (unless a request already carries its own `As` header).
     */
    public function withImpersonation(string|int $userId): self
    {
        $this->impersonateAs = (string) $userId;

        return $this;
    }

    public function build(): Client
    {
        $httpClient = $this->httpClient ?? Psr18ClientDiscovery::find();
        $requestFactory = $this->requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = $this->streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        // Regular resource calls (folders, files, roots, tokens) live under
        // `{baseUri}/api` — there is no further "module" segment to
        // configure, "module" in Voltn's docs is just a placeholder
        // word for "whatever resource path applies" (folder, file, ...).
        $apiBaseUri = rtrim($this->baseUri, '/') . '/api';
        // OAuth2 endpoints sit at the tenant root, not under `/api`.
        $oauth2BaseUri = rtrim($this->baseUri, '/');
        $tusBaseUri = rtrim($this->baseUri, '/') . '/api/tus';

        $oauth2Client = null;

        if ($this->clientId !== null) {
            $oauth2Transport = new HttpTransport($httpClient, $requestFactory, $streamFactory, $oauth2BaseUri);
            $oauth2Client = new OAuth2Client($oauth2Transport, $this->clientId);
        }

        $refresher = $this->buildRefresher($oauth2Client);

        $authenticationMiddleware = new AuthenticationMiddleware(
            $httpClient,
            $this->accessToken,
            $refresher,
            $this->tokenStorage,
            $this->impersonateAs,
        );

        $transport = new HttpTransport($authenticationMiddleware, $requestFactory, $streamFactory, $apiBaseUri);
        $tusTransport = new HttpTransport($authenticationMiddleware, $requestFactory, $streamFactory, $tusBaseUri);

        return new Client($transport, $tusTransport, $authenticationMiddleware, $requestFactory, $streamFactory, $oauth2Client);
    }

    /**
     * @return null|(Closure(): AccessToken)
     */
    private function buildRefresher(?OAuth2Client $oauth2Client): ?Closure
    {
        if ($oauth2Client === null) {
            return null;
        }

        if ($this->useClientCredentials) {
            $clientSecret = $this->clientSecret ?? throw new InvalidArgumentException(
                'withClientCredentials() requires a client secret.',
            );

            return static fn (): AccessToken => $oauth2Client->clientCredentials($clientSecret);
        }

        // Full interactive flow: refresh using whatever refresh token the
        // current access token (initial, or last persisted) carries.
        $tokenStorage = $this->tokenStorage;
        $initialToken = $this->accessToken;
        $clientSecret = $this->clientSecret;

        return static function () use ($oauth2Client, $tokenStorage, $initialToken, $clientSecret): AccessToken {
            $current = $tokenStorage?->get() ?? $initialToken;
            $refreshToken = $current?->getRefreshToken();

            if ($refreshToken === null || $refreshToken === '') {
                throw new AuthenticationException(
                    'No refresh token is available to refresh the current Voltn access token.',
                );
            }

            return $oauth2Client->refresh($refreshToken, $clientSecret);
        };
    }

    private static function validateBaseUri(string $baseUri): string
    {
        $parts = parse_url($baseUri);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Voltn base URI "%s": expected a URL with a scheme and host, e.g. "https://tenant.voltn.example".',
                $baseUri,
            ));
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Voltn base URI "%s": scheme must be "http" or "https".',
                $baseUri,
            ));
        }

        $path = $parts['path'] ?? '';

        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException(sprintf(
                'Invalid Voltn base URI "%s": expected the tenant host only, without a path (the SDK appends "/api" itself).',
                $baseUri,
            ));
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Voltn base URI "%s": expected the tenant host only, without a query string or fragment.',
                $baseUri,
            ));
        }

        return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
