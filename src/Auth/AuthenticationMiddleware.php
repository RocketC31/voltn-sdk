<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RocketC31\Voltn\Exception\AuthenticationException;

/**
 * PSR-18 {@see ClientInterface} decorator that transparently injects the
 * `Authorization: Bearer <token>` header (and, if configured, the `As:`
 * impersonation header) into every outgoing request, and implements the
 * three usage modes described in the SDK's authentication model:
 *
 * 1. Fire-and-forget `client_credentials`: no {@see TokenStorage} is
 *    required; a fresh token is transparently obtained via the supplied
 *    refresh callback whenever the current one is missing, close to
 *    expiry, or rejected with a 401.
 * 2. Full interactive flow: a {@see TokenStorage} is supplied; refreshed
 *    tokens are persisted back through it.
 * 3. Fully manual: no refresh callback is configured at all. The
 *    middleware still attaches whatever {@see AccessToken} it currently
 *    holds, but a 401 is never retried — it propagates as-is, since there
 *    is no way for the middleware to obtain a new token on its own.
 *
 * On a 401 response, the middleware refreshes the token (if it is able to)
 * and retries the request exactly once with the new token. If the request
 * body is a non-seekable stream, the retry is skipped (the body may have
 * already been partially consumed by the failed attempt) and the 401
 * response is returned as-is.
 */
final class AuthenticationMiddleware implements ClientInterface
{
    /**
     * Refresh tokens proactively when they are within this many seconds of
     * expiring, to avoid a request racing the expiry boundary.
     */
    private const EXPIRY_LEEWAY_SECONDS = 30;

    private ?AccessToken $currentToken;

    /**
     * @param ClientInterface           $httpClient the underlying, undecorated PSR-18 client
     * @param null|(\Closure(): AccessToken) $refresher callback used to obtain a
     *        new token, e.g. `fn () => $oauth2Client->clientCredentials($secret)`
     *        or `fn () => $oauth2Client->refresh($token->getRefreshToken())`.
     *        Null in the fully-manual usage mode.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        ?AccessToken $initialToken = null,
        private readonly ?\Closure $refresher = null,
        private readonly ?TokenStorage $tokenStorage = null,
        private readonly ?string $impersonateAs = null,
    ) {
        $this->currentToken = $initialToken ?? $tokenStorage?->get();
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $token = $this->resolveToken();
        $preparedRequest = $this->applyAuthHeaders($request, $token);

        $response = $this->httpClient->sendRequest($preparedRequest);

        if ($response->getStatusCode() !== 401 || $this->refresher === null) {
            return $response;
        }

        if (!$this->isRetryable($preparedRequest)) {
            return $response;
        }

        $refreshedToken = $this->refreshToken();
        $retryRequest = $this->applyAuthHeaders($this->rewind($preparedRequest), $refreshedToken);

        return $this->httpClient->sendRequest($retryRequest);
    }

    /**
     * The token currently held by the middleware, if any (does not trigger
     * a refresh).
     */
    public function getCurrentToken(): ?AccessToken
    {
        return $this->currentToken;
    }

    /**
     * Resolve the token to use for the next outgoing request: the current
     * one if it is not close to expiring, otherwise a freshly refreshed one
     * (when a refresher is configured). In the fully-manual mode (no
     * refresher), the current token is always returned as-is, even if it
     * looks expired — the SDK has no way to renew it on its own, and doing
     * nothing lets the request go out and fail naturally (surfaced as an
     * {@see AuthenticationException}) rather than the SDK silently
     * fabricating a decision the caller didn't ask for.
     */
    private function resolveToken(): ?AccessToken
    {
        if ($this->refresher === null) {
            return $this->currentToken;
        }

        if ($this->currentToken === null || $this->currentToken->expiresWithin(self::EXPIRY_LEEWAY_SECONDS)) {
            return $this->refreshToken();
        }

        return $this->currentToken;
    }

    private function refreshToken(): AccessToken
    {
        if ($this->refresher === null) {
            throw new AuthenticationException('No access token is available and no refresh mechanism is configured.');
        }

        $token = ($this->refresher)();
        $this->currentToken = $token;
        $this->tokenStorage?->set($token);

        return $token;
    }

    private function applyAuthHeaders(RequestInterface $request, ?AccessToken $token): RequestInterface
    {
        if ($token !== null) {
            $request = $request->withHeader(
                'Authorization',
                sprintf('%s %s', $token->getTokenType(), $token->getAccessToken()),
            );
        }

        if ($this->impersonateAs !== null && !$request->hasHeader('As')) {
            $request = $request->withHeader('As', $this->impersonateAs);
        }

        return $request;
    }

    private function isRetryable(RequestInterface $request): bool
    {
        $body = $request->getBody();

        if ($body->getSize() === 0) {
            return true;
        }

        return $body->isSeekable();
    }

    private function rewind(RequestInterface $request): RequestInterface
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $request->withBody($body);
    }
}
