<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

use InvalidArgumentException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\HttpTransport;

/**
 * Client for Voltn's OAuth2 endpoints: `GET /oauth2/authorize`
 * (browser redirect, not an API call) and `POST /oauth2/token` (all four
 * grant flavours the API supports: authorization_code, refresh_token, and
 * client_credentials).
 *
 * This client intentionally does not go through
 * {@see \RocketC31\Voltn\Auth\AuthenticationMiddleware}: obtaining a token
 * is, by definition, unauthenticated (or authenticated only via the
 * client_id/client_secret/code carried in the request body itself).
 */
final class OAuth2Client
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $clientId,
    ) {
    }

    /**
     * Build the URL the end user should be redirected to in order to grant
     * access (authorization_code grant, step 1). Supports RFC 7636 PKCE by
     * passing a {@see PkcePair} generated via {@see Pkce::generate()}.
     */
    public function getAuthorizationUrl(
        string $redirectUri,
        ?string $state = null,
        string $scope = 'all',
        ?PkcePair $pkce = null,
    ): string {
        $query = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => $redirectUri,
        ];

        if ($state !== null) {
            $query['state'] = $state;
        }

        if ($pkce !== null) {
            $query['code_challenge'] = $pkce->codeChallenge;
            $query['code_challenge_method'] = $pkce->codeChallengeMethod;
        }

        $baseUri = rtrim($this->transport->getBaseUri(), '/');

        return $baseUri . '/oauth2/authorize?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an authorization code for an access token (authorization_code
     * grant, step 2). Exactly one of `$clientSecret` (confidential client)
     * or `$codeVerifier` (PKCE, public client) must be provided.
     */
    public function exchangeAuthorizationCode(
        string $code,
        string $redirectUri,
        ?string $clientSecret = null,
        ?string $codeVerifier = null,
    ): AccessToken {
        if ($clientSecret === null && $codeVerifier === null) {
            throw new InvalidArgumentException(
                'exchangeAuthorizationCode() requires either a client secret or a PKCE code verifier.',
            );
        }

        $body = [
            'client_id' => $this->clientId,
            'grant_type' => GrantType::AuthorizationCode->value,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];

        if ($clientSecret !== null) {
            $body['client_secret'] = $clientSecret;
        }

        if ($codeVerifier !== null) {
            $body['code_verifier'] = $codeVerifier;
        }

        return $this->requestToken($body);
    }

    /**
     * Exchange a refresh token for a new access token.
     */
    public function refresh(string $refreshToken, ?string $clientSecret = null): AccessToken
    {
        $body = [
            'client_id' => $this->clientId,
            'grant_type' => GrantType::RefreshToken->value,
            'refresh_token' => $refreshToken,
        ];

        if ($clientSecret !== null) {
            $body['client_secret'] = $clientSecret;
        }

        return $this->requestToken($body);
    }

    /**
     * Obtain an access token via the client_credentials grant (no user
     * interaction; this is what a fire-and-forget backend integration,
     * e.g. a backup job, would use).
     */
    public function clientCredentials(string $clientSecret): AccessToken
    {
        $body = [
            'client_id' => $this->clientId,
            'grant_type' => GrantType::ClientCredentials->value,
            'client_secret' => $clientSecret,
        ];

        return $this->requestToken($body);
    }

    /**
     * @param array<string, string> $body
     */
    private function requestToken(array $body): AccessToken
    {
        $response = $this->transport->sendForJson('POST', '/oauth2/token', null, $body);

        if ($response === null) {
            throw new UnexpectedResponseException('The /oauth2/token endpoint returned an empty response body.');
        }

        return AccessToken::fromTokenResponse($response);
    }
}
