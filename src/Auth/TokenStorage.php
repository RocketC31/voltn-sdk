<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

/**
 * A place to persist the current {@see AccessToken} across requests (e.g.
 * a session, cache, or database). Implement this to let
 * {@see AuthenticationMiddleware} transparently refresh and persist tokens
 * for the "full interactive flow" and "fire-and-forget client_credentials"
 * usage modes.
 */
interface TokenStorage
{
    public function get(): ?AccessToken;

    public function set(AccessToken $token): void;

    public function clear(): void;
}
