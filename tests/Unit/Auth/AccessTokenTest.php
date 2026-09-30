<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Auth\AccessToken;

final class AccessTokenTest extends TestCase
{
    public function testFromTokenResponseComputesAbsoluteExpiry(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $token = AccessToken::fromTokenResponse([
            'access_token' => 'abc123',
            'refresh_token' => 'refresh123',
            'token_type' => 'Bearer',
            'scope' => 'all',
            'expires_in' => 3600,
        ], $now);

        self::assertSame('abc123', $token->getAccessToken());
        self::assertSame('refresh123', $token->getRefreshToken());
        self::assertSame('Bearer', $token->getTokenType());
        self::assertSame('all', $token->getScope());
        self::assertEquals(new DateTimeImmutable('2026-01-01T01:00:00+00:00'), $token->getExpiresAt());
    }

    public function testFromTokenResponseWithoutExpiresInIsTreatedAsNonExpiring(): void
    {
        $token = AccessToken::fromTokenResponse([
            'access_token' => 'abc123',
            'token_type' => 'Bearer',
        ]);

        self::assertNull($token->getExpiresAt());
        self::assertFalse($token->isExpired());
        self::assertFalse($token->expiresWithin(3600));
    }

    public function testFromTokenResponseWithNullRefreshToken(): void
    {
        // client_credentials grant: refresh_token is null.
        $token = AccessToken::fromTokenResponse([
            'access_token' => 'abc123',
            'refresh_token' => null,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);

        self::assertNull($token->getRefreshToken());
        self::assertFalse($token->canRefresh());
    }

    public function testDefaultsTokenTypeToBearerWhenMissing(): void
    {
        $token = AccessToken::fromTokenResponse(['access_token' => 'abc123']);

        self::assertSame('Bearer', $token->getTokenType());
    }

    public function testIsExpiredIsFalseBeforeExpiryAndTrueAfter(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $token = AccessToken::fromTokenResponse(['access_token' => 'abc', 'expires_in' => 60], $now);

        self::assertFalse($token->isExpired(new DateTimeImmutable('2026-01-01T00:00:59+00:00')));
        self::assertTrue($token->isExpired(new DateTimeImmutable('2026-01-01T00:01:00+00:00')));
        self::assertTrue($token->isExpired(new DateTimeImmutable('2026-01-01T00:01:01+00:00')));
    }

    public function testExpiresWithinDetectsUpcomingExpiry(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $token = AccessToken::fromTokenResponse(['access_token' => 'abc', 'expires_in' => 60], $now);

        // 30 seconds before expiry, asking "expires within 60s?" -> true
        self::assertTrue($token->expiresWithin(60, new DateTimeImmutable('2026-01-01T00:00:30+00:00')));
        // Right after receipt, asking "expires within 10s?" -> false
        self::assertFalse($token->expiresWithin(10, $now));
    }

    public function testToArrayAndFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $token = AccessToken::fromTokenResponse([
            'access_token' => 'abc123',
            'refresh_token' => 'refresh123',
            'token_type' => 'Bearer',
            'scope' => 'all',
            'expires_in' => 3600,
        ], $now);

        $rehydrated = AccessToken::fromArray($token->toArray());

        self::assertSame($token->getAccessToken(), $rehydrated->getAccessToken());
        self::assertSame($token->getRefreshToken(), $rehydrated->getRefreshToken());
        self::assertSame($token->getTokenType(), $rehydrated->getTokenType());
        self::assertSame($token->getScope(), $rehydrated->getScope());
        self::assertEquals($token->getExpiresAt(), $rehydrated->getExpiresAt());
    }

    public function testToArrayWithNullExpiryRoundTrips(): void
    {
        $token = AccessToken::fromTokenResponse(['access_token' => 'abc123']);

        $array = $token->toArray();
        self::assertNull($array['expires_at']);

        $rehydrated = AccessToken::fromArray($array);
        self::assertNull($rehydrated->getExpiresAt());
    }

    public function testWithAccessTokenReturnsNewInstanceWithUpdatedToken(): void
    {
        $token = AccessToken::fromTokenResponse(['access_token' => 'old', 'refresh_token' => 'r']);
        $updated = $token->withAccessToken('new');

        self::assertSame('old', $token->getAccessToken());
        self::assertSame('new', $updated->getAccessToken());
        self::assertSame('r', $updated->getRefreshToken());
    }

    public function testNonNumericExpiresInIsTreatedAsNonExpiring(): void
    {
        $token = AccessToken::fromTokenResponse(['access_token' => 'abc', 'expires_in' => 'not-a-number']);

        self::assertNull($token->getExpiresAt());
    }
}
