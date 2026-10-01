<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use InvalidArgumentException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\ExceptionFactory;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\File;
use RocketC31\Voltn\Model\Folder;
use RocketC31\Voltn\Model\ObjectType;
use RocketC31\Voltn\Model\Quotas;

/**
 * `$client->sync()`: path-based lookups and quotas (Voltn's
 * "Synchronisation" module), handy to address items by path (e.g. from a
 * Flysystem adapter) instead of walking folders id by id.
 */
final class SyncClient
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Resolve a folder by its path below `$rootFolderId` (`GET /path`).
     * A trailing `/` is added if missing, as the platform uses it to tell
     * folders from files.
     *
     * @throws \RocketC31\Voltn\Exception\NotFoundException when nothing exists at that path
     */
    public function folderAt(int|string $rootFolderId, string $path): Folder
    {
        $path = self::normalize($path);

        return Folder::fromArray($this->lookup($rootFolderId, $path === '' ? '/' : $path . '/'));
    }

    /**
     * Resolve a file by its path below `$rootFolderId` (`GET /path`).
     *
     * @throws \RocketC31\Voltn\Exception\NotFoundException when nothing exists at that path
     */
    public function fileAt(int|string $rootFolderId, string $path): File
    {
        $path = self::normalize($path);

        if ($path === '') {
            throw new InvalidArgumentException('A file path cannot be empty.');
        }

        return File::fromArray($this->lookup($rootFolderId, $path));
    }

    /**
     * Path of an item relative to `$rootFolderId` (`GET /objectpath`),
     * e.g. `Documents/Partagé/informations_diverses.docx`.
     */
    public function objectPath(int|string $rootFolderId, ObjectType $type, int|string $objectId): string
    {
        $data = $this->transport->sendForJson('GET', '/objectpath', [
            'root' => $rootFolderId,
            'object_type' => $type->value,
            'object_id' => $objectId,
        ]);

        $path = $data['object'] ?? null;

        if (!is_string($path)) {
            throw new UnexpectedResponseException('The /objectpath endpoint returned no path.');
        }

        return $path;
    }

    /**
     * Whether `$folderId` is below `$rootFolderId` (`GET /ischildof`), in a
     * single call whatever the depth. True on 200, false on 404; a 403
     * (child, but not accessible to the caller) propagates as an
     * {@see \RocketC31\Voltn\Exception\AuthorizationException}.
     */
    public function isChildOf(int|string $folderId, int|string $rootFolderId): bool
    {
        $request = $this->transport->createRequest('GET', '/ischildof', [
            'root' => $rootFolderId,
            'folder' => $folderId,
        ]);
        $response = $this->transport->dispatch($request);

        return match ($response->getStatusCode()) {
            200 => true,
            404 => false,
            default => throw ExceptionFactory::fromResponse($response, $request),
        };
    }

    /**
     * Platform, current user and optionally folder quotas (`GET /quotas`).
     */
    public function quotas(int|string|null $folderId = null): Quotas
    {
        $data = $this->transport->sendForJson('GET', '/quotas', $folderId !== null ? ['folder' => $folderId] : null);

        return Quotas::fromArray($data ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function lookup(int|string $rootFolderId, string $path): array
    {
        $data = $this->transport->sendForJson('GET', '/path', ['root' => $rootFolderId, 'path' => $path]);
        $object = $data['object'] ?? null;

        if (!is_array($object)) {
            throw new UnexpectedResponseException('The /path endpoint returned no object.');
        }

        /** @var array<string, mixed> $object */
        return $object;
    }

    private static function normalize(string $path): string
    {
        return trim($path, '/');
    }
}
