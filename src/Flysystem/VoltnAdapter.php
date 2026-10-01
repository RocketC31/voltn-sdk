<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Flysystem;

use GuzzleHttp\Psr7\StreamWrapper;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\WhitespacePathNormalizer;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Psr\Http\Message\StreamInterface;
use RocketC31\Voltn\Client;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\VoltnException;
use RocketC31\Voltn\Model\File;
use RocketC31\Voltn\Model\Folder;
use RocketC31\Voltn\Upload\UploadOptions;
use RuntimeException;
use Throwable;

/**
 * Flysystem v3 adapter storing files in a Voltn folder.
 *
 * Every Flysystem path is relative to `$rootFolderId` and resolved in a
 * single call through `$client->sync()` (Voltn's `GET /path`). Notes:
 *
 * - Writing to an existing path creates a new Voltn version of the file
 *   (the platform's native behaviour), it does not fail.
 * - Visibility is not supported by Voltn: {@see self::setVisibility()} and
 *   {@see self::visibility()} always throw.
 * - MIME types are derived from the file extension (Voltn does not expose
 *   one).
 * - Contents larger than `$chunkedUploadThreshold` bytes (when their size is
 *   known) are uploaded through TUS in `$chunkSize` chunks; smaller ones
 *   through a single streamed multipart upload.
 */
final class VoltnAdapter implements FilesystemAdapter
{
    private const COPY_BUFFER_SIZE = 1048576;

    private const TEMP_STREAM_MAX_MEMORY = 5242880;

    private readonly PathNormalizer $pathNormalizer;

    private readonly MimeTypeDetector $mimeTypeDetector;

    /**
     * @param int|string $rootFolderId           id of the Voltn folder acting as the
     *                                           filesystem root
     * @param bool       $trash                  true to send deleted items to the
     *                                           (recoverable) trash, false to delete
     *                                           them permanently
     * @param int        $chunkedUploadThreshold contents strictly larger than this
     *                                           (bytes) go through TUS
     * @param int        $chunkSize              TUS chunk size in bytes
     */
    public function __construct(
        private readonly Client $client,
        private readonly int|string $rootFolderId,
        private readonly bool $trash = true,
        private readonly int $chunkedUploadThreshold = 52428800,
        private readonly int $chunkSize = 8388608,
    ) {
        $this->pathNormalizer = new WhitespacePathNormalizer();
        $this->mimeTypeDetector = new ExtensionMimeTypeDetector();
    }

    public function fileExists(string $path): bool
    {
        $path = $this->normalize($path);

        if ($path === '') {
            return false;
        }

        try {
            $this->client->sync()->fileAt($this->rootFolderId, $path);

            return true;
        } catch (NotFoundException) {
            return false;
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        $path = $this->normalize($path);

        if ($path === '') {
            return true;
        }

        try {
            $this->client->sync()->folderAt($this->rootFolderId, $path);

            return true;
        } catch (NotFoundException) {
            return false;
        } catch (Throwable $exception) {
            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->normalize($path);

        try {
            $stream = $this->client->getTransport()->getStreamFactory()->createStream($contents);
            $this->upload($path, $stream, strlen($contents));
        } catch (FilesystemException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * @param resource $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $path = $this->normalize($path);

        if (!is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'The provided contents is not a valid stream resource.');
        }

        $size = self::remainingSize($contents);
        $stream = null;

        try {
            $stream = $this->client->getTransport()->getStreamFactory()->createStreamFromResource($contents);
            $this->upload($path, $stream, $size);
        } catch (FilesystemException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        } finally {
            // The caller owns the resource: release it from the PSR-7
            // wrapper so it isn't closed when the wrapper is destroyed.
            $stream?->detach();
        }
    }

    public function read(string $path): string
    {
        $path = $this->normalize($path);

        try {
            return (string) $this->download($path);
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function readStream(string $path)
    {
        $path = $this->normalize($path);

        try {
            return self::toResource($this->download($path));
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        $path = $this->normalize($path);

        if ($path === '') {
            throw UnableToDeleteFile::atLocation($path, 'A file path cannot be empty.');
        }

        try {
            $file = $this->client->sync()->fileAt($this->rootFolderId, $path);
            $this->client->files()->delete($file->getId(), trash: $this->trash);
        } catch (NotFoundException) {
            // Deleting a file that does not exist is a no-op.
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $path = $this->normalize($path);

        if ($path === '') {
            throw UnableToDeleteDirectory::atLocation($path, 'Refusing to delete the root folder of the Voltn adapter.');
        }

        try {
            $folder = $this->client->sync()->folderAt($this->rootFolderId, $path);
            $this->client->folders()->delete($folder->getId(), trash: $this->trash);
        } catch (NotFoundException) {
            // Deleting a directory that does not exist is a no-op.
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $path = $this->normalize($path);

        try {
            $this->ensureDirectory($path);
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($this->normalize($path), 'Voltn does not support visibility.');
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($this->normalize($path), 'Voltn does not support visibility.');
    }

    public function mimeType(string $path): FileAttributes
    {
        $path = $this->normalize($path);
        $this->resolveFileForMetadata($path, FileAttributes::ATTRIBUTE_MIME_TYPE);

        $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'The MIME type cannot be derived from the file extension.');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $path = $this->normalize($path);
        $lastModified = $this->resolveFileForMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED)->lastModified();

        if ($lastModified === null) {
            throw UnableToRetrieveMetadata::lastModified($path, 'Voltn did not report a last modification date.');
        }

        return new FileAttributes($path, lastModified: $lastModified->getTimestamp());
    }

    public function fileSize(string $path): FileAttributes
    {
        $path = $this->normalize($path);
        $size = $this->resolveFileForMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE)->size();

        if ($size === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Voltn did not report a file size.');
        }

        return new FileAttributes($path, fileSize: $size);
    }

    /**
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $path = $this->normalize($path);

        try {
            $folderId = $path === ''
                ? $this->rootFolderId
                : $this->client->sync()->folderAt($this->rootFolderId, $path)->getId();
        } catch (NotFoundException) {
            return;
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }

        yield from $this->listFolder($folderId, $path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->normalize($source);
        $destination = $this->normalize($destination);

        try {
            $file = $this->copyFile($source, $destination);
            $this->client->files()->delete($file->getId(), trash: $this->trash);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = $this->normalize($source);
        $destination = $this->normalize($destination);

        try {
            $this->copyFile($source, $destination);
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * Normalize a Flysystem path to a slash-free relative path; rejects
     * traversal above the adapter root.
     */
    private function normalize(string $path): string
    {
        return $this->pathNormalizer->normalizePath($path);
    }

    private function upload(string $path, StreamInterface $content, ?int $size): File
    {
        [$directory, $filename] = self::split($path);

        if ($filename === '') {
            throw new RuntimeException('A file path cannot be empty.');
        }

        $folderId = $this->ensureDirectory($directory);
        $contentType = $this->mimeTypeDetector->detectMimeTypeFromPath($filename) ?? 'application/octet-stream';

        if ($size !== null && $size > $this->chunkedUploadThreshold) {
            return $this->client->tus()->upload(
                target: $folderId,
                filename: $filename,
                content: $content,
                size: $size,
                options: new UploadOptions(chunkSize: $this->chunkSize, contentType: $contentType),
            );
        }

        return $this->client->files()->upload($folderId, $filename, $content, $contentType);
    }

    private function download(string $path): StreamInterface
    {
        if ($path === '') {
            throw new RuntimeException('A file path cannot be empty.');
        }

        $file = $this->client->sync()->fileAt($this->rootFolderId, $path);

        return $this->client->files()->download($file->getId());
    }

    /**
     * Stream `$source` into `$destination` and return the source file.
     */
    private function copyFile(string $source, string $destination): File
    {
        if ($source === '' || $destination === '') {
            throw new RuntimeException('A file path cannot be empty.');
        }

        $file = $this->client->sync()->fileAt($this->rootFolderId, $source);
        $content = $this->client->files()->download($file->getId());

        $this->upload($destination, $content, $file->size());

        return $file;
    }

    /**
     * Make sure the folder at `$path` exists, creating every missing segment,
     * and return its id.
     */
    private function ensureDirectory(string $path): int|string
    {
        if ($path === '') {
            return $this->rootFolderId;
        }

        try {
            return $this->client->sync()->folderAt($this->rootFolderId, $path)->getId();
        } catch (NotFoundException) {
            // Walk the segments below.
        }

        $segments = explode('/', $path);
        $last = count($segments) - 1;
        $parentId = $this->rootFolderId;
        $current = '';
        $mustCreate = false;

        foreach ($segments as $index => $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            // The full path is already known to be missing.
            if (!$mustCreate && $index < $last) {
                try {
                    $parentId = $this->client->sync()->folderAt($this->rootFolderId, $current)->getId();

                    continue;
                } catch (NotFoundException) {
                    // Once a segment is missing, every deeper one is too.
                    $mustCreate = true;
                }
            }

            $parentId = $this->createFolder($parentId, $segment, $current);
        }

        return $parentId;
    }

    private function createFolder(int|string $parentId, string $name, string $path): int|string
    {
        try {
            return $this->client->folders()->create($parentId, $name)->getId();
        } catch (VoltnException $exception) {
            // A concurrent writer may have just created it (Voltn refuses
            // duplicate folder names): use it if it now exists.
            try {
                return $this->client->sync()->folderAt($this->rootFolderId, $path)->getId();
            } catch (VoltnException) {
                throw $exception;
            }
        }
    }

    private function resolveFileForMetadata(string $path, string $type): File
    {
        try {
            if ($path === '') {
                throw new RuntimeException('A file path cannot be empty.');
            }

            return $this->client->sync()->fileAt($this->rootFolderId, $path);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, $exception->getMessage(), $exception);
        }
    }

    /**
     * @return iterable<StorageAttributes>
     */
    private function listFolder(int|string $folderId, string $path, bool $deep): iterable
    {
        try {
            $folder = $this->client->folders()->get($folderId, depth: 1);
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }

        foreach ($folder->getFolders() as $child) {
            $childPath = self::join($path, $child->getName());

            yield $this->directoryAttributes($childPath, $child);

            if ($deep) {
                yield from $this->listFolder($child->getId(), $childPath, true);
            }
        }

        foreach ($folder->getFiles() as $file) {
            yield $this->fileAttributes(self::join($path, $file->getName()), $file);
        }
    }

    private function directoryAttributes(string $path, Folder $folder): DirectoryAttributes
    {
        return new DirectoryAttributes($path, lastModified: $folder->getUpdatedAt()?->getTimestamp());
    }

    private function fileAttributes(string $path, File $file): FileAttributes
    {
        return new FileAttributes(
            $path,
            $file->size(),
            null,
            $file->lastModified()?->getTimestamp(),
            $this->mimeTypeDetector->detectMimeTypeFromPath($path),
        );
    }

    /**
     * @return array{string, string} directory (possibly empty) and basename
     */
    private static function split(string $path): array
    {
        $position = strrpos($path, '/');

        if ($position === false) {
            return ['', $path];
        }

        return [substr($path, 0, $position), substr($path, $position + 1)];
    }

    private static function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory . '/' . $name;
    }

    /**
     * Number of bytes left to read from `$resource`, when it can be known
     * reliably (seekable local stream); null otherwise.
     *
     * @param resource $resource
     */
    private static function remainingSize($resource): ?int
    {
        $meta = stream_get_meta_data($resource);
        $stat = fstat($resource);

        if (!$meta['seekable'] || $stat === false) {
            return null;
        }

        $position = ftell($resource);

        return max(0, $stat['size'] - ($position === false ? 0 : $position));
    }

    /**
     * Expose a PSR-7 stream as a PHP resource without loading it fully into
     * memory.
     *
     * @return resource
     */
    private static function toResource(StreamInterface $stream)
    {
        if (class_exists(StreamWrapper::class)) {
            return StreamWrapper::getResource($stream);
        }

        $resource = fopen(sprintf('php://temp/maxmemory:%d', self::TEMP_STREAM_MAX_MEMORY), 'w+b');

        if ($resource === false) {
            throw new RuntimeException('Unable to open a temporary stream.');
        }

        while (!$stream->eof()) {
            $chunk = $stream->read(self::COPY_BUFFER_SIZE);

            if ($chunk === '') {
                break;
            }

            fwrite($resource, $chunk);
        }

        rewind($resource);

        return $resource;
    }
}
