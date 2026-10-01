<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

use DateTimeImmutable;

/**
 * A Voltn file.
 *
 * Note: Voltn does not expose a real MIME-type field on files, only
 * the coarse `file_type` (`image` / `document` / `video`, used by the web
 * UI to pick a preview icon) — there is intentionally no `getMimeType()`
 * accessor here. A future Flysystem adapter built on top of this SDK will
 * need to derive MIME type from the file extension itself.
 */
final class File
{
    /**
     * @param list<FileVersion> $versions
     */
    private function __construct(
        private readonly int|string $id,
        private readonly string $name,
        private readonly ?string $guid,
        private readonly int|string|null $folderId,
        private readonly ?int $size,
        private readonly ?string $hash,
        private readonly ?string $fileType,
        private readonly ?DateTimeImmutable $createdAt,
        private readonly ?DateTimeImmutable $lastModified,
        private readonly ?LockInfo $lock,
        private readonly bool $canRead,
        private readonly bool $canWrite,
        private readonly bool $canDelete,
        private readonly bool $canShare,
        private readonly array $versions,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lockData = $data['lock'] ?? null;

        return new self(
            id: DtoHelper::intOrString($data['id'] ?? null) ?? 0,
            name: DtoHelper::nullableString($data['name'] ?? null) ?? '',
            guid: DtoHelper::nullableString($data['guid'] ?? null),
            folderId: DtoHelper::intOrString($data['folder_id'] ?? $data['folderId'] ?? null),
            size: DtoHelper::nullableInt($data['size'] ?? null),
            hash: DtoHelper::nullableString($data['hash'] ?? null),
            fileType: DtoHelper::nullableString($data['file_type'] ?? $data['fileType'] ?? null),
            createdAt: DtoHelper::nullableDate($data['created_at'] ?? $data['createdAt'] ?? null),
            lastModified: DtoHelper::nullableDate(
                $data['last_modified'] ?? $data['updated_at'] ?? $data['updatedAt'] ?? null,
            ),
            lock: is_array($lockData) ? LockInfo::fromArray($lockData) : null,
            canRead: DtoHelper::boolOrDefault($data['can_read'] ?? null, true),
            canWrite: DtoHelper::boolOrDefault($data['can_write'] ?? null, false),
            canDelete: DtoHelper::boolOrDefault($data['can_delete'] ?? null, false),
            canShare: DtoHelper::boolOrDefault($data['can_share'] ?? null, false),
            versions: array_map(
                static fn (mixed $version): FileVersion => FileVersion::fromArray(is_array($version) ? $version : []),
                DtoHelper::arrayOrEmpty($data['versions'] ?? null),
            ),
        );
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Global identifier shared by all versions of the file: unlike the id,
     * which designates one version, the guid always leads to the file as a
     * whole (e.g. to reach its latest version).
     */
    public function getGuid(): ?string
    {
        return $this->guid;
    }

    public function getFolderId(): int|string|null
    {
        return $this->folderId;
    }

    /**
     * File size in bytes, or null if not reported by the API.
     */
    public function size(): ?int
    {
        return $this->size;
    }

    /**
     * MD5 hash of the file content, or null if not reported by the API.
     */
    public function hash(): ?string
    {
        return $this->hash;
    }

    /**
     * Coarse file kind used for preview purposes: `image`, `document`,
     * `video`, or another platform-defined string. Not a MIME type.
     */
    public function getFileType(): ?string
    {
        return $this->fileType;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastModified(): ?DateTimeImmutable
    {
        return $this->lastModified;
    }

    public function getLock(): ?LockInfo
    {
        return $this->lock;
    }

    public function isLocked(): bool
    {
        return $this->lock !== null;
    }

    public function canRead(): bool
    {
        return $this->canRead;
    }

    public function canWrite(): bool
    {
        return $this->canWrite;
    }

    public function canDelete(): bool
    {
        return $this->canDelete;
    }

    public function canShare(): bool
    {
        return $this->canShare;
    }

    /**
     * @return list<FileVersion>
     */
    public function getVersions(): array
    {
        return $this->versions;
    }
}
