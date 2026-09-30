<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

use DateTimeImmutable;

/**
 * A short-lived download token generated via
 * `POST /token/file/(fileId)/(type)`, typically used to build a temporary
 * public download link for a file without exposing the caller's own
 * access token.
 */
final class DownloadToken
{
    private function __construct(
        private readonly string $token,
        private readonly ?string $url,
        private readonly ?DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            token: DtoHelper::nullableString($data['token'] ?? null) ?? '',
            url: DtoHelper::nullableString($data['url'] ?? null),
            expiresAt: DtoHelper::nullableDate($data['expires_at'] ?? $data['expiresAt'] ?? null),
        );
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return $now >= $this->expiresAt;
    }
}
