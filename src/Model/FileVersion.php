<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

use DateTimeImmutable;

/**
 * A single historical version of a {@see File}. Voltn creates a new
 * version automatically whenever `POST /file/upload` targets a file that
 * already exists.
 */
final class FileVersion
{
    private function __construct(
        private readonly int|string|null $id,
        private readonly ?int $versionNumber,
        private readonly ?int $size,
        private readonly ?string $hash,
        private readonly ?DateTimeImmutable $createdAt,
        private readonly int|string|null $createdBy,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: DtoHelper::intOrString($data['id'] ?? null),
            versionNumber: DtoHelper::nullableInt($data['version'] ?? $data['version_number'] ?? null),
            size: DtoHelper::nullableInt($data['size'] ?? null),
            hash: DtoHelper::nullableString($data['hash'] ?? null),
            createdAt: DtoHelper::nullableDate($data['creation'] ?? $data['created_at'] ?? $data['createdAt'] ?? null),
            createdBy: DtoHelper::intOrString($data['created_by'] ?? $data['createdBy'] ?? null),
        );
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getVersionNumber(): ?int
    {
        return $this->versionNumber;
    }

    public function size(): ?int
    {
        return $this->size;
    }

    public function hash(): ?string
    {
        return $this->hash;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): int|string|null
    {
        return $this->createdBy;
    }
}
