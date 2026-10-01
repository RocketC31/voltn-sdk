<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * One quota entry returned by `GET /quotas`. A null quota means no limit
 * is applied; `used` is always filled.
 */
final class QuotaUsage
{
    private function __construct(
        private readonly ?int $quota,
        private readonly int $used,
        private readonly int|string|null $folderId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            quota: DtoHelper::nullableInt($data['quota'] ?? null),
            used: DtoHelper::nullableInt($data['used'] ?? null) ?? 0,
            folderId: DtoHelper::intOrString($data['folder'] ?? null),
        );
    }

    /** Limit in bytes, or null when unlimited. */
    public function getQuota(): ?int
    {
        return $this->quota;
    }

    /** Used space in bytes. */
    public function getUsed(): int
    {
        return $this->used;
    }

    /** Remaining space in bytes, or null when unlimited. */
    public function getRemaining(): ?int
    {
        return $this->quota === null ? null : max(0, $this->quota - $this->used);
    }

    /**
     * Folder quota only: id of the folder the limit is actually set on when
     * it is inherited, null otherwise.
     */
    public function getFolderId(): int|string|null
    {
        return $this->folderId;
    }
}
