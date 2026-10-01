<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * Result of `GET /quotas`: platform-wide, current user and (when asked)
 * folder quotas.
 */
final class Quotas
{
    private function __construct(
        private readonly QuotaUsage $platform,
        private readonly QuotaUsage $user,
        private readonly ?QuotaUsage $folder,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $platform = $data['platform'] ?? null;
        $user = $data['user'] ?? null;
        $folder = $data['folder'] ?? null;

        return new self(
            platform: QuotaUsage::fromArray(is_array($platform) ? $platform : []),
            user: QuotaUsage::fromArray(is_array($user) ? $user : []),
            folder: is_array($folder) ? QuotaUsage::fromArray($folder) : null,
        );
    }

    public function getPlatform(): QuotaUsage
    {
        return $this->platform;
    }

    public function getUser(): QuotaUsage
    {
        return $this->user;
    }

    public function getFolder(): ?QuotaUsage
    {
        return $this->folder;
    }
}
