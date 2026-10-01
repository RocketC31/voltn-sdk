<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use InvalidArgumentException;
use RocketC31\Voltn\Exception\NotFoundException;
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

        // `GET /path` answers 404 for the root itself ("/"): read it directly.
        if ($path === '') {
            return Folder::fromArray($this->transport->sendForJson(
                'GET',
                sprintf('/folder/%s', rawurlencode((string) $rootFolderId)),
            ) ?? []);
        }

        return Folder::fromArray($this->lookup($rootFolderId, $path . '/'));
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

        $object = $this->lookup($rootFolderId, $path);

        // Without a trailing "/" the platform also matches folders: only file
        // objects carry a size / guid.
        if (!array_key_exists('size', $object) && !array_key_exists('guid', $object)) {
            throw new NotFoundException(sprintf('No file at "%s" (a folder exists there).', $path), 404);
        }

        return File::fromArray($object);
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

        // The platform returns the object itself; the documentation shows it
        // wrapped in {"object": …}: accept both.
        $object = isset($data['object']) && is_array($data['object']) ? $data['object'] : $data;

        if (!is_array($object) || !isset($object['id'])) {
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
