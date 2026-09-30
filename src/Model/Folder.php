<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

use DateTimeImmutable;

/**
 * A Voltn folder.
 *
 * `getFiles()`/`getFolders()` reflect `content.files`/`content.folders`
 * from the API response and are only populated when the folder was fetched
 * with a `depth` query parameter (see `FolderClient::get()`); otherwise
 * they are empty lists, not null — callers that need to distinguish
 * "not fetched" from "empty folder" should re-fetch with an explicit
 * depth.
 */
final class Folder
{
    /**
     * @param list<File>   $files
     * @param list<Folder> $folders
     */
    private function __construct(
        private readonly int|string $id,
        private readonly string $name,
        private readonly int|string|null $parentId,
        private readonly ?string $path,
        private readonly ?DateTimeImmutable $createdAt,
        private readonly ?DateTimeImmutable $updatedAt,
        private readonly bool $canRead,
        private readonly bool $canWrite,
        private readonly bool $canDelete,
        private readonly bool $canShare,
        private readonly array $files,
        private readonly array $folders,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $content = $data['content'] ?? null;
        $content = is_array($content) ? $content : [];

        return new self(
            id: DtoHelper::intOrString($data['id'] ?? null) ?? 0,
            name: DtoHelper::nullableString($data['name'] ?? null) ?? '',
            parentId: DtoHelper::intOrString($data['parent_id'] ?? $data['parentId'] ?? null),
            path: DtoHelper::nullableString($data['path'] ?? null),
            createdAt: DtoHelper::nullableDate($data['created_at'] ?? $data['createdAt'] ?? null),
            updatedAt: DtoHelper::nullableDate($data['updated_at'] ?? $data['updatedAt'] ?? null),
            canRead: DtoHelper::boolOrDefault($data['can_read'] ?? null, true),
            canWrite: DtoHelper::boolOrDefault($data['can_write'] ?? null, false),
            canDelete: DtoHelper::boolOrDefault($data['can_delete'] ?? null, false),
            canShare: DtoHelper::boolOrDefault($data['can_share'] ?? null, false),
            files: array_map(
                static fn (mixed $file): File => File::fromArray(is_array($file) ? $file : []),
                DtoHelper::arrayOrEmpty($content['files'] ?? null),
            ),
            folders: array_map(
                static fn (mixed $folder): self => self::fromArray(is_array($folder) ? $folder : []),
                DtoHelper::arrayOrEmpty($content['folders'] ?? null),
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

    public function getParentId(): int|string|null
    {
        return $this->parentId;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
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
     * @return list<File>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    /**
     * @return list<Folder>
     */
    public function getFolders(): array
    {
        return $this->folders;
    }
}
