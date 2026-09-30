<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * A Voltn root space (top-level container a user/organization has
 * access to), as returned by `GET /roots` and `GET /root/(type)`.
 */
final class RootSpace
{
    private function __construct(
        private readonly int|string|null $id,
        private readonly ?string $type,
        private readonly ?string $name,
        private readonly ?Folder $folder,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $folderData = $data['folder'] ?? null;

        return new self(
            id: DtoHelper::intOrString($data['id'] ?? null),
            type: DtoHelper::nullableString($data['type'] ?? null),
            name: DtoHelper::nullableString($data['name'] ?? null),
            folder: is_array($folderData) ? Folder::fromArray($folderData) : null,
        );
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * The root's underlying folder, when the API response embeds one.
     */
    public function getFolder(): ?Folder
    {
        return $this->folder;
    }
}
