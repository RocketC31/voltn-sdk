<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use Psr\Http\Message\StreamInterface;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Http\MultipartFilePart;
use RocketC31\Voltn\Model\File;
use RocketC31\Voltn\Model\FileVersionScope;

/**
 * `$client->files()`: read/upload/download/update/delete Voltn
 * files.
 *
 * Uploads and downloads are streamed: none of these methods buffer a full
 * file's content into memory.
 */
final class FileClient
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    public function get(int|string $fileId): File
    {
        $data = $this->transport->sendForJson('GET', sprintf('/file/%s', rawurlencode((string) $fileId)));

        return File::fromArray($data ?? []);
    }

    /**
     * Fetch a file's metadata (same shape as {@see self::get()} on most
     * Voltn deployments, exposed separately per the documented
     * `/file/(fileId)/infos` endpoint).
     */
    public function infos(int|string $fileId): File
    {
        $data = $this->transport->sendForJson('GET', sprintf('/file/%s/infos', rawurlencode((string) $fileId)));

        return File::fromArray($data ?? []);
    }

    /**
     * Download a file's content as a stream. The stream is not read here —
     * it is the caller's responsibility to consume it (e.g. copy it to a
     * local file or another stream) without buffering it fully into
     * memory.
     */
    public function download(int|string $fileId): StreamInterface
    {
        return $this->transport->sendForStream('GET', sprintf('/file/%s/download', rawurlencode((string) $fileId)));
    }

    /**
     * Upload a file into `$folderId`. If a file with the same name already
     * exists in that folder, Voltn creates a new version of it
     * instead of failing.
     *
     * For large files, prefer `$client->tus()` instead, to get chunked,
     * resumable uploads.
     */
    public function upload(int|string $folderId, string $filename, StreamInterface $content, string $contentType = 'application/octet-stream'): File
    {
        $data = $this->transport->sendMultipart(
            'POST',
            '/file/upload',
            ['folderId' => $folderId],
            [new MultipartFilePart('targetFile', $filename, $content, $contentType)],
        );

        return File::fromArray($data ?? []);
    }

    /**
     * Replace an existing file's binary content in place (keeping its
     * metadata), without going through the multipart upload endpoint.
     */
    public function replaceContent(int|string $fileId, StreamInterface $content, string $contentType = 'application/octet-stream'): File
    {
        $request = $this->transport
            ->createRequest('PUT', sprintf('/file/%s/upload', rawurlencode((string) $fileId)))
            ->withHeader('Content-Type', $contentType)
            ->withBody($content);

        $data = $this->transport->sendPreparedRequestForJson($request);

        return File::fromArray($data ?? []);
    }

    /**
     * Update a file's metadata (e.g. rename, move by changing `folderId`).
     *
     * @param array<string, mixed> $changes
     */
    public function update(int|string $fileId, array $changes): File
    {
        $data = $this->transport->sendForJson('PUT', sprintf('/file/%s', rawurlencode((string) $fileId)), null, $changes);

        return File::fromArray($data ?? []);
    }

    /**
     * Delete a file. By default it goes to the trash (recoverable) with all
     * of its versions; pass `trash: false` to delete it permanently, and
     * `$versions` to only affect some versions.
     */
    public function delete(int|string $fileId, bool $trash = true, FileVersionScope $versions = FileVersionScope::All): void
    {
        $query = [];

        if (!$trash) {
            $query['trash'] = 0;
        }

        if ($versions !== FileVersionScope::All) {
            $query['versions'] = $versions->value;
        }

        $this->transport->sendForJson(
            'DELETE',
            sprintf('/file/%s', rawurlencode((string) $fileId)),
            $query !== [] ? $query : null,
        );
    }

    /**
     * True if the file exists and is visible to the authenticated
     * principal; false on a 404. Any other error still propagates.
     */
    public function exists(int|string $fileId): bool
    {
        try {
            $this->get($fileId);

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }
}
