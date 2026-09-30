<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

/**
 * A generated RFC 7636 PKCE code verifier / code challenge pair.
 *
 * The `code_verifier` must be sent as-is when exchanging the authorization
 * code (`exchangeAuthorizationCode()`); the `code_challenge` (together with
 * `code_challenge_method`) is what gets sent to `/oauth2/authorize`.
 */
final class PkcePair
{
    public function __construct(
        public readonly string $codeVerifier,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod = 'S256',
    ) {
    }
}
