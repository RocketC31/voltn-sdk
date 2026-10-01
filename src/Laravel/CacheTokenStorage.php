<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Laravel;

use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\Auth\TokenStorage;
use Throwable;

/**
 * A {@see TokenStorage} backed by a Laravel cache repository, so that
 * successive requests/jobs reuse the same access token instead of asking
 * Voltn for a new one every time. The token is encrypted at rest with the
 * application encrypter.
 *
 * The cache entry expires 60 seconds before the token does (or after 23
 * hours for a non-expiring token); any unreadable, corrupted or expired
 * entry is treated as absent.
 */
final class CacheTokenStorage implements TokenStorage
{
    private const EXPIRY_MARGIN_SECONDS = 60;

    private const NON_EXPIRING_TTL_SECONDS = 82800;

    public function __construct(
        private readonly Repository $cache,
        private readonly StringEncrypter $encrypter,
        private readonly string $key,
    ) {
    }

    /**
     * Cache key for the token of a given tenant and OAuth2 client.
     */
    public static function keyFor(string $baseUri, string $clientId): string
    {
        return 'voltn:token:' . sha1($baseUri . '|' . $clientId);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): ?AccessToken
    {
        try {
            $payload = $this->cache->get($this->key);

            if (!is_string($payload) || $payload === '') {
                return null;
            }

            $data = json_decode($this->encrypter->decryptString($payload), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return null;
            }

            /** @var array<string, mixed> $data */
            $token = AccessToken::fromArray($data);
        } catch (Throwable) {
            return null;
        }

        if ($token->getAccessToken() === '' || $token->isExpired()) {
            return null;
        }

        return $token;
    }

    public function set(AccessToken $token): void
    {
        $ttl = $this->ttlFor($token);

        if ($ttl <= 0) {
            $this->clear();

            return;
        }

        $this->cache->put(
            $this->key,
            $this->encrypter->encryptString(json_encode($token->toArray(), JSON_THROW_ON_ERROR)),
            $ttl,
        );
    }

    public function clear(): void
    {
        $this->cache->forget($this->key);
    }

    private function ttlFor(AccessToken $token): int
    {
        $expiresAt = $token->getExpiresAt();

        if ($expiresAt === null) {
            return self::NON_EXPIRING_TTL_SECONDS;
        }

        return $expiresAt->getTimestamp() - (new DateTimeImmutable())->getTimestamp() - self::EXPIRY_MARGIN_SECONDS;
    }
}
