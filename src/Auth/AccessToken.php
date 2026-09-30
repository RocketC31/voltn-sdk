<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Immutable OAuth2 access token.
 *
 * Voltn token responses carry a relative `expires_in` (seconds); this
 * value object converts that into an absolute {@see DateTimeInterface} at
 * the moment the token is received, so expiry checks later on don't need to
 * remember "when did we get this token". `expires_in` may be entirely
 * absent for tokens issued with the `offline` scope (non-expiring), in
 * which case {@see self::getExpiresAt()} is null and the token is treated
 * as never expiring.
 */
final class AccessToken
{
    private function __construct(
        private readonly string $accessToken,
        private readonly ?string $refreshToken,
        private readonly string $tokenType,
        private readonly ?string $scope,
        private readonly ?DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * Build a token from a raw `/oauth2/token` response body, resolving the
     * relative `expires_in` (seconds) into an absolute instant relative to
     * `$now` (defaults to the current time).
     *
     * @param array<string, mixed> $data
     */
    public static function fromTokenResponse(array $data, ?DateTimeImmutable $now = null): self
    {
        $now ??= new DateTimeImmutable();

        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: self::nullableString($data['refresh_token'] ?? null),
            tokenType: self::nullableString($data['token_type'] ?? null) ?? 'Bearer',
            scope: self::nullableString($data['scope'] ?? null),
            expiresAt: self::resolveExpiresAt($data['expires_in'] ?? null, $now),
        );
    }

    /**
     * Rebuild a token from the array produced by {@see self::toArray()},
     * e.g. when loading a previously persisted token from a
     * {@see TokenStorage}. Unlike {@see self::fromTokenResponse()}, the
     * `expires_at` key here is already an absolute ISO 8601 timestamp.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = self::nullableString($data['expires_at'] ?? null);

        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: self::nullableString($data['refresh_token'] ?? null),
            tokenType: self::nullableString($data['token_type'] ?? null) ?? 'Bearer',
            scope: self::nullableString($data['scope'] ?? null),
            expiresAt: $expiresAt !== null ? new DateTimeImmutable($expiresAt) : null,
        );
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, token_type: string, scope: ?string, expires_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getTokenType(): string
    {
        return $this->tokenType;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Null means the token does not expire (offline / non-expiring scope).
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function canRefresh(): bool
    {
        return $this->refreshToken !== null && $this->refreshToken !== '';
    }

    /**
     * A token with no `expiresAt` is treated as never expiring.
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return $now >= $this->expiresAt;
    }

    /**
     * True if the token is already expired, or will expire within
     * `$seconds` seconds of `$now`. Used to trigger a proactive refresh
     * slightly ahead of the hard expiry boundary.
     */
    public function expiresWithin(int $seconds, ?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();
        $threshold = $now->modify(sprintf('+%d seconds', $seconds));

        return $threshold >= $this->expiresAt;
    }

    public function withAccessToken(string $accessToken): self
    {
        return new self($accessToken, $this->refreshToken, $this->tokenType, $this->scope, $this->expiresAt);
    }

    private static function resolveExpiresAt(mixed $expiresIn, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($expiresIn === null || $expiresIn === '') {
            return null;
        }

        if (!is_numeric($expiresIn)) {
            return null;
        }

        return $now->modify(sprintf('+%d seconds', (int) $expiresIn));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
