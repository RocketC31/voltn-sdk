<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\Folder;

/**
 * `$client->folders()`: create/read/update/delete Voltn folders.
 */
final class FolderClient
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Fetch a folder by id.
     *
     * @param int|null   $depth    how many levels of subfolders/files to
     *                             include inline via `content.files`/
     *                             `content.folders` (omit for a shallow
     *                             fetch)
     * @param bool|null  $full     whether to request the "full" response
     *                             shape (platform-defined)
     */
    public function get(int|string $folderId, ?int $depth = null, ?bool $full = null): Folder
    {
        $query = [];

        if ($depth !== null) {
            $query['depth'] = $depth;
        }

        if ($full !== null) {
            $query['full'] = $full ? 'true' : 'false';
        }

        $data = $this->transport->sendForJson('GET', sprintf('/folder/%s', rawurlencode((string) $folderId)), $query !== [] ? $query : null);

        return Folder::fromArray($data ?? []);
    }

    /**
     * List root-level folders.
     *
     * @return list<Folder>
     */
    public function listRoots(): array
    {
        $data = $this->transport->sendForJson('GET', '/folders');

        $items = $data['folders'] ?? $data['items'] ?? $data ?? [];

        if (!is_array($items)) {
            return [];
        }

        return array_map(
            static fn (mixed $item): Folder => Folder::fromArray(is_array($item) ? $item : []),
            array_values($items),
        );
    }

    /**
     * Create a folder inside `$parentFolderId`.
     */
    public function create(int|string $parentFolderId, string $name): Folder
    {
        $data = $this->transport->sendForJson('POST', '/folder', null, [
            'parent_id' => $parentFolderId,
            'name' => $name,
        ]);

        return Folder::fromArray($data ?? []);
    }

    /**
     * Update a folder's metadata (e.g. rename or move by changing
     * `parent_id`).
     *
     * @param array<string, mixed> $changes
     */
    public function update(int|string $folderId, array $changes): Folder
    {
        $data = $this->transport->sendForJson('PUT', sprintf('/folder/%s', rawurlencode((string) $folderId)), null, $changes);

        return Folder::fromArray($data ?? []);
    }

    public function delete(int|string $folderId): void
    {
        $this->transport->sendForJson('DELETE', sprintf('/folder/%s', rawurlencode((string) $folderId)));
    }

    /**
     * True if the folder exists and is visible to the authenticated
     * principal; false on a 404. Any other error still propagates.
     */
    public function exists(int|string $folderId): bool
    {
        try {
            $this->get($folderId);

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }
}
