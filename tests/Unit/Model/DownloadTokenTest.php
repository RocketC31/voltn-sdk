<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Model;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Model\DownloadToken;

final class DownloadTokenTest extends TestCase
{
    public function testFromArrayMapsFields(): void
    {
        $token = DownloadToken::fromArray([
            'token' => 'abc123',
            'url' => 'https://tenant.example.test/download/abc123',
            'expires_at' => '2026-01-01T00:10:00+00:00',
        ]);

        self::assertSame('abc123', $token->getToken());
        self::assertSame('https://tenant.example.test/download/abc123', $token->getUrl());
        self::assertNotNull($token->getExpiresAt());
    }

    public function testFromArrayHandlesEmptyArray(): void
    {
        $token = DownloadToken::fromArray([]);

        self::assertSame('', $token->getToken());
        self::assertNull($token->getUrl());
        self::assertNull($token->getExpiresAt());
        self::assertFalse($token->isExpired());
    }

    public function testIsExpired(): void
    {
        $token = DownloadToken::fromArray(['token' => 'abc', 'expires_at' => '2026-01-01T00:00:00+00:00']);

        self::assertTrue($token->isExpired(new DateTimeImmutable('2026-01-01T00:00:01+00:00')));
        self::assertFalse($token->isExpired(new DateTimeImmutable('2025-12-31T23:59:59+00:00')));
    }
}
