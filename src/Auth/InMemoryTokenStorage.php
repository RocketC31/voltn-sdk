<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

/**
 * A {@see TokenStorage} that keeps the token in memory for the lifetime of
 * the PHP process only. Suitable for the fire-and-forget `client_credentials`
 * usage mode (e.g. a long-running or single-shot backup
 * process) or for quick scripts/tests; not suitable for anything that needs
 * the token to survive across requests (use a session/cache/DB-backed
 * implementation for that).
 */
final class InMemoryTokenStorage implements TokenStorage
{
    private ?AccessToken $token = null;

    public function __construct(?AccessToken $initialToken = null)
    {
        $this->token = $initialToken;
    }

    public function get(): ?AccessToken
    {
        return $this->token;
    }

    public function set(AccessToken $token): void
    {
        $this->token = $token;
    }

    public function clear(): void
    {
        $this->token = null;
    }
}
