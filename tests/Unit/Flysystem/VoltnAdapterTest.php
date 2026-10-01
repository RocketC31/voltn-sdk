<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Flysystem;

use GuzzleHttp\Psr7\Response;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\ClientBuilder;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\ServerException;
use RocketC31\Voltn\Flysystem\VoltnAdapter;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class VoltnAdapterTest extends TestCase
{
    private const ROOT = 7;

    private const API = 'https://tenant.example.test/api';

    private MockTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new MockTransportFactory();
    }

    private function makeAdapter(bool $trash = true, int $threshold = 52428800, int $chunkSize = 8388608): VoltnAdapter
    {
        $client = ClientBuilder::create('https://tenant.example.test')
            ->withHttpClient($this->factory->getClient())
            ->withRequestFactory($this->factory->getRequestFactory())
            ->withStreamFactory($this->factory->getStreamFactory())
            ->withAccessToken(AccessToken::fromArray(['access_token' => 'test-token']))
            ->build();

        return new VoltnAdapter($client, self::ROOT, $trash, $threshold, $chunkSize);
    }

    private static function pathUri(string $path): string
    {
        return self::API . '/path?' . http_build_query(['root' => self::ROOT, 'path' => $path], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function queueObject(array $object): void
    {
        $this->factory->queueJson(200, ['object' => $object]);
    }

    private function queueNotFound(): void
    {
        $this->factory->queueJson(404, ['error' => 'not found']);
    }

    /**
     * @return list<RequestInterface>
     */
    private function requests(): array
    {
        return $this->factory->getRequestHistory();
    }

    /**
     * @return list<string>
     */
    private function requestLines(): array
    {
        return array_map(
            static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri(),
            $this->requests(),
        );
    }

    public function testFileExistsIsTrueWhenThePathResolves(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'x.zip']);

        self::assertTrue($this->makeAdapter()->fileExists('/backups/x.zip'));
        self::assertSame(['GET ' . self::pathUri('backups/x.zip')], $this->requestLines());
        self::assertSame('Bearer test-token', $this->requests()[0]->getHeaderLine('Authorization'));
    }

    public function testFileExistsIsFalseOnNotFound(): void
    {
        $this->queueNotFound();

        self::assertFalse($this->makeAdapter()->fileExists('missing.zip'));
    }

    public function testFileExistsWrapsOtherErrors(): void
    {
        $this->factory->queueResponse(500);

        $this->expectException(UnableToCheckExistence::class);

        $this->makeAdapter()->fileExists('x.zip');
    }

    public function testDirectoryExistsUsesTrailingSlash(): void
    {
        $this->queueObject(['id' => 12, 'name' => 'backups']);
        $this->queueNotFound();

        $adapter = $this->makeAdapter();

        self::assertTrue($adapter->directoryExists('backups'));
        self::assertFalse($adapter->directoryExists('nope/'));
        self::assertSame(
            ['GET ' . self::pathUri('backups/'), 'GET ' . self::pathUri('nope/')],
            $this->requestLines(),
        );
    }

    public function testTheRootDirectoryAlwaysExists(): void
    {
        self::assertTrue($this->makeAdapter()->directoryExists(''));
        self::assertTrue($this->makeAdapter()->directoryExists('/'));
        self::assertSame(0, $this->factory->count());
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(PathTraversalDetected::class);

        $this->makeAdapter()->fileExists('../outside.zip');
    }

    public function testWriteCreatesMissingParentsAndUploadsTheBasename(): void
    {
        $this->queueNotFound();                                   // folderAt backups/site
        $this->queueNotFound();                                   // folderAt backups
        $this->factory->queueJson(200, ['id' => 20, 'name' => 'backups']);   // POST /folder
        $this->factory->queueJson(200, ['id' => 21, 'name' => 'site']);      // POST /folder
        $this->factory->queueJson(200, ['id' => 99, 'name' => 'db.zip']);    // upload

        $this->makeAdapter()->write('backups/site/db.zip', 'zip-bytes', new Config());

        self::assertSame([
            'GET ' . self::pathUri('backups/site/'),
            'GET ' . self::pathUri('backups/'),
            'POST ' . self::API . '/folder',
            'POST ' . self::API . '/folder',
            'POST ' . self::API . '/file/upload',
        ], $this->requestLines());

        $requests = $this->requests();
        self::assertSame(['parent_id' => self::ROOT, 'name' => 'backups'], json_decode((string) $requests[2]->getBody(), true));
        self::assertSame(['parent_id' => 20, 'name' => 'site'], json_decode((string) $requests[3]->getBody(), true));

        $upload = $requests[4];
        self::assertStringStartsWith('multipart/form-data; boundary=', $upload->getHeaderLine('Content-Type'));
        $body = (string) $upload->getBody();
        self::assertStringContainsString("name=\"folderId\"\r\n\r\n21\r\n", $body);
        self::assertStringContainsString('filename="db.zip"', $body);
        self::assertStringContainsString('Content-Type: application/zip', $body);
        self::assertStringContainsString('zip-bytes', $body);
    }

    public function testWriteReusesExistingParents(): void
    {
        $this->queueNotFound();                                   // folderAt a/b
        $this->queueObject(['id' => 30, 'name' => 'a']);          // folderAt a
        $this->factory->queueJson(200, ['id' => 31, 'name' => 'b']);
        $this->factory->queueJson(200, ['id' => 99]);

        $this->makeAdapter()->write('a/b/c.txt', 'hello', new Config());

        self::assertSame([
            'GET ' . self::pathUri('a/b/'),
            'GET ' . self::pathUri('a/'),
            'POST ' . self::API . '/folder',
            'POST ' . self::API . '/file/upload',
        ], $this->requestLines());
        self::assertSame(['parent_id' => 30, 'name' => 'b'], json_decode((string) $this->requests()[2]->getBody(), true));
    }

    public function testWriteToTheRootUploadsDirectly(): void
    {
        $this->factory->queueJson(200, ['id' => 99]);

        $this->makeAdapter()->write('x.txt', 'hello', new Config());

        self::assertSame(['POST ' . self::API . '/file/upload'], $this->requestLines());
        self::assertStringContainsString("name=\"folderId\"\r\n\r\n7\r\n", (string) $this->requests()[0]->getBody());
    }

    public function testWriteFallsBackToAFolderCreatedConcurrently(): void
    {
        $this->queueNotFound();                                   // folderAt a
        $this->factory->queueJson(400, ['error' => 'already exists']);   // POST /folder
        $this->queueObject(['id' => 40, 'name' => 'a']);          // folderAt a (retry)
        $this->factory->queueJson(200, ['id' => 99]);

        $this->makeAdapter()->write('a/x.txt', 'hello', new Config());

        self::assertSame([
            'GET ' . self::pathUri('a/'),
            'POST ' . self::API . '/folder',
            'GET ' . self::pathUri('a/'),
            'POST ' . self::API . '/file/upload',
        ], $this->requestLines());
        self::assertStringContainsString("name=\"folderId\"\r\n\r\n40\r\n", (string) $this->requests()[3]->getBody());
    }

    public function testWriteStreamUploadsTheResourceAndLeavesItOpen(): void
    {
        $sentBody = null;
        $this->factory->queueCallback(static function (RequestInterface $request) use (&$sentBody): Response {
            $sentBody = (string) $request->getBody();

            return new Response(200, ['Content-Type' => 'application/json'], '{"id":99}');
        });

        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, 'streamed-content');
        rewind($resource);

        $this->makeAdapter()->writeStream('x.bin', $resource, new Config());

        self::assertIsString($sentBody);
        self::assertStringContainsString('streamed-content', $sentBody);
        self::assertIsResource($resource);
        fclose($resource);
    }

    public function testLargeStreamGoesThroughTus(): void
    {
        $this->factory->queueJson(200, ['token' => 'session-abc']);                              // POST /file/tus
        $this->factory->queueResponse(201, '', ['Location' => self::API . '/tus/upload-xyz']);  // TUS create
        $this->factory->queueResponse(204, '', ['Upload-Offset' => '10']);                       // PATCH
        $this->factory->queueResponse(204, '', ['Upload-Offset' => '20']);                       // PATCH
        $this->factory->queueJson(200, ['id' => 55]);                                            // finalize

        $resource = fopen('php://temp', 'w+b');
        self::assertIsResource($resource);
        fwrite($resource, str_repeat('a', 20));
        rewind($resource);

        $this->makeAdapter(threshold: 10, chunkSize: 10)->writeStream('big.zip', $resource, new Config());
        fclose($resource);

        $requests = $this->requests();
        self::assertSame([
            'POST ' . self::API . '/file/tus',
            'POST ' . self::API . '/tus',
            'PATCH ' . self::API . '/tus/upload-xyz',
            'PATCH ' . self::API . '/tus/upload-xyz',
            'POST ' . self::API . '/file/tus',
        ], $this->requestLines());
        self::assertSame(['name' => 'big.zip', 'target' => self::ROOT, 'size' => 20], json_decode((string) $requests[0]->getBody(), true));
        self::assertSame('10', $requests[3]->getHeaderLine('Upload-Offset'));
    }

    public function testStringAboveThresholdGoesThroughTus(): void
    {
        $this->factory->queueJson(200, ['token' => 'session-abc']);
        $this->factory->queueResponse(201, '', ['Location' => '/upload-xyz']);
        $this->factory->queueResponse(204, '', ['Upload-Offset' => '6']);
        $this->factory->queueJson(200, ['id' => 55]);

        $this->makeAdapter(threshold: 5)->write('x.txt', 'abcdef', new Config());

        self::assertSame('POST ' . self::API . '/file/tus', $this->requestLines()[0]);
        self::assertSame(4, $this->factory->count());
    }

    public function testWriteErrorsAreWrapped(): void
    {
        $this->factory->queueResponse(500);

        try {
            $this->makeAdapter()->write('x.txt', 'hello', new Config());
            self::fail('Expected UnableToWriteFile.');
        } catch (UnableToWriteFile $exception) {
            self::assertSame('x.txt', $exception->location());
            self::assertInstanceOf(ServerException::class, $exception->getPrevious());
        }
    }

    public function testReadReturnsTheContents(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'x.txt']);
        $this->factory->queueResponse(200, 'file-content');

        self::assertSame('file-content', $this->makeAdapter()->read('dir/x.txt'));
        self::assertSame([
            'GET ' . self::pathUri('dir/x.txt'),
            'GET ' . self::API . '/file/99/download',
        ], $this->requestLines());
    }

    public function testReadStreamReturnsAResource(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'x.txt']);
        $this->factory->queueResponse(200, 'file-content');

        $resource = $this->makeAdapter()->readStream('x.txt');

        self::assertIsResource($resource);
        self::assertSame('file-content', stream_get_contents($resource));
        fclose($resource);
    }

    public function testReadingAMissingFileThrows(): void
    {
        $this->queueNotFound();

        try {
            $this->makeAdapter()->read('missing.txt');
            self::fail('Expected UnableToReadFile.');
        } catch (UnableToReadFile $exception) {
            self::assertInstanceOf(NotFoundException::class, $exception->getPrevious());
        }
    }

    public function testReadStreamWrapsErrors(): void
    {
        $this->queueObject(['id' => 99]);
        $this->factory->queueResponse(500);

        $this->expectException(UnableToReadFile::class);

        $this->makeAdapter()->readStream('x.txt');
    }

    public function testDeleteSendsToTrashByDefault(): void
    {
        $this->queueObject(['id' => 99]);
        $this->factory->queueResponse(204);

        $this->makeAdapter()->delete('x.zip');

        self::assertSame('DELETE ' . self::API . '/file/99', $this->requestLines()[1]);
    }

    public function testDeleteIsPermanentWhenTrashIsDisabled(): void
    {
        $this->queueObject(['id' => 99]);
        $this->factory->queueResponse(204);

        $this->makeAdapter(trash: false)->delete('x.zip');

        self::assertSame('DELETE ' . self::API . '/file/99?trash=0', $this->requestLines()[1]);
    }

    public function testDeletingAMissingFileIsANoOp(): void
    {
        $this->queueNotFound();

        $this->makeAdapter()->delete('missing.zip');

        self::assertSame(1, $this->factory->count());
    }

    public function testDeleteWrapsOtherErrors(): void
    {
        $this->queueObject(['id' => 99]);
        $this->factory->queueResponse(403);

        $this->expectException(UnableToDeleteFile::class);

        $this->makeAdapter()->delete('x.zip');
    }

    public function testDeleteDirectory(): void
    {
        $this->queueObject(['id' => 12]);
        $this->factory->queueResponse(204);

        $this->makeAdapter(trash: false)->deleteDirectory('old/');

        self::assertSame([
            'GET ' . self::pathUri('old/'),
            'DELETE ' . self::API . '/folder/12?trash=0',
        ], $this->requestLines());
    }

    public function testDeletingAMissingDirectoryIsANoOp(): void
    {
        $this->queueNotFound();

        $this->makeAdapter()->deleteDirectory('missing');

        self::assertSame(1, $this->factory->count());
    }

    public function testDeletingTheRootIsRefused(): void
    {
        $this->expectException(UnableToDeleteDirectory::class);

        try {
            $this->makeAdapter()->deleteDirectory('/');
        } finally {
            self::assertSame(0, $this->factory->count());
        }
    }

    public function testDeleteDirectoryWrapsErrors(): void
    {
        $this->queueObject(['id' => 12]);
        $this->factory->queueResponse(500);

        $this->expectException(UnableToDeleteDirectory::class);

        $this->makeAdapter()->deleteDirectory('old');
    }

    public function testCreateDirectoryIsIdempotent(): void
    {
        $this->queueObject(['id' => 12]);

        $this->makeAdapter()->createDirectory('existing', new Config());

        self::assertSame(['GET ' . self::pathUri('existing/')], $this->requestLines());
    }

    public function testCreateDirectoryCreatesMissingSegments(): void
    {
        $this->queueNotFound();
        $this->queueNotFound();
        $this->factory->queueJson(200, ['id' => 20]);
        $this->factory->queueJson(200, ['id' => 21]);

        $this->makeAdapter()->createDirectory('a/b', new Config());

        self::assertSame(4, $this->factory->count());
        self::assertSame(['parent_id' => 20, 'name' => 'b'], json_decode((string) $this->requests()[3]->getBody(), true));
    }

    public function testCreateDirectoryWrapsErrors(): void
    {
        $this->factory->queueResponse(500);

        $this->expectException(UnableToCreateDirectory::class);

        $this->makeAdapter()->createDirectory('a', new Config());
    }

    public function testSetVisibilityIsUnsupported(): void
    {
        $this->expectException(UnableToSetVisibility::class);

        $this->makeAdapter()->setVisibility('x.txt', 'public');
    }

    public function testVisibilityIsUnsupported(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        $this->makeAdapter()->visibility('x.txt');
    }

    public function testMimeTypeComesFromTheExtension(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'report.pdf']);

        $attributes = $this->makeAdapter()->mimeType('docs/report.pdf');

        self::assertSame('application/pdf', $attributes->mimeType());
        self::assertSame('docs/report.pdf', $attributes->path());
        self::assertSame(1, $this->factory->count());
    }

    public function testMimeTypeOfAMissingFileThrows(): void
    {
        $this->queueNotFound();

        $this->expectException(UnableToRetrieveMetadata::class);

        $this->makeAdapter()->mimeType('missing.pdf');
    }

    public function testMimeTypeOfAnUnknownExtensionThrows(): void
    {
        $this->queueObject(['id' => 99]);

        $this->expectException(UnableToRetrieveMetadata::class);

        $this->makeAdapter()->mimeType('file.unknown-ext');
    }

    public function testFileSizeAndLastModified(): void
    {
        $object = ['id' => 99, 'size' => 45678, 'last_modified' => '2025-06-01T08:00:00+00:00'];
        $this->queueObject($object);
        $this->queueObject($object);

        $adapter = $this->makeAdapter();

        self::assertSame(45678, $adapter->fileSize('x.zip')->fileSize());
        self::assertSame(1748764800, $adapter->lastModified('x.zip')->lastModified());
    }

    public function testMissingMetadataThrows(): void
    {
        $this->queueObject(['id' => 99]);
        $this->queueObject(['id' => 99]);
        $this->queueNotFound();

        $adapter = $this->makeAdapter();

        foreach (['fileSize', 'lastModified', 'fileSize'] as $method) {
            try {
                $adapter->{$method}('x.zip');
                self::fail(sprintf('Expected %s() to throw.', $method));
            } catch (UnableToRetrieveMetadata) {
                // Expected.
            }
        }

        self::assertSame(3, $this->factory->count());
    }

    public function testListContentsShallow(): void
    {
        $this->queueObject(['id' => 12, 'name' => 'backups']);
        $this->factory->queueJson(200, [
            'id' => 12,
            'name' => 'backups',
            'content' => [
                'folders' => [['id' => 13, 'name' => 'site', 'updated_at' => '2025-06-01T08:00:00+00:00']],
                'files' => [['id' => 99, 'name' => 'db.zip', 'size' => 10, 'last_modified' => '2025-06-01T08:00:00+00:00']],
            ],
        ]);

        $items = iterator_to_array($this->makeAdapter()->listContents('/backups/', false), false);

        self::assertCount(2, $items);
        self::assertInstanceOf(DirectoryAttributes::class, $items[0]);
        self::assertSame('backups/site', $items[0]->path());
        self::assertSame(1748764800, $items[0]->lastModified());
        self::assertInstanceOf(FileAttributes::class, $items[1]);
        self::assertSame('backups/db.zip', $items[1]->path());
        self::assertSame(10, $items[1]->fileSize());
        self::assertSame(1748764800, $items[1]->lastModified());
        self::assertSame('application/zip', $items[1]->mimeType());
        self::assertSame([
            'GET ' . self::pathUri('backups/'),
            'GET ' . self::API . '/folder/12?depth=1',
        ], $this->requestLines());
    }

    public function testListContentsDeepFromTheRoot(): void
    {
        $this->factory->queueJson(200, [
            'id' => self::ROOT,
            'content' => [
                'folders' => [['id' => 13, 'name' => 'a']],
                'files' => [['id' => 1, 'name' => 'top.txt']],
            ],
        ]);
        $this->factory->queueJson(200, [
            'id' => 13,
            'content' => [
                'folders' => [['id' => 14, 'name' => 'b']],
                'files' => [['id' => 2, 'name' => 'mid.txt']],
            ],
        ]);
        $this->factory->queueJson(200, [
            'id' => 14,
            'content' => ['files' => [['id' => 3, 'name' => 'deep.txt']]],
        ]);

        $items = iterator_to_array($this->makeAdapter()->listContents('', true), false);

        self::assertSame(
            ['dir:a', 'dir:a/b', 'file:a/b/deep.txt', 'file:a/mid.txt', 'file:top.txt'],
            array_map(
                static fn (StorageAttributes $item): string => ($item->isDir() ? 'dir:' : 'file:') . $item->path(),
                $items,
            ),
        );
        self::assertSame([
            'GET ' . self::API . '/folder/7?depth=1',
            'GET ' . self::API . '/folder/13?depth=1',
            'GET ' . self::API . '/folder/14?depth=1',
        ], $this->requestLines());
    }

    public function testListingAMissingDirectoryYieldsNothing(): void
    {
        $this->queueNotFound();

        self::assertSame([], iterator_to_array($this->makeAdapter()->listContents('missing', true), false));
    }

    public function testCopyStreamsTheSourceIntoTheDestination(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'a.txt', 'size' => 7]);    // fileAt source
        $this->factory->queueResponse(200, 'content');                        // download
        $this->queueObject(['id' => 50, 'name' => 'dest']);                   // folderAt dest
        $this->factory->queueJson(200, ['id' => 100]);                        // upload

        $this->makeAdapter()->copy('a.txt', 'dest/b.txt', new Config());

        self::assertSame([
            'GET ' . self::pathUri('a.txt'),
            'GET ' . self::API . '/file/99/download',
            'GET ' . self::pathUri('dest/'),
            'POST ' . self::API . '/file/upload',
        ], $this->requestLines());
        $body = (string) $this->requests()[3]->getBody();
        self::assertStringContainsString("name=\"folderId\"\r\n\r\n50\r\n", $body);
        self::assertStringContainsString('filename="b.txt"', $body);
        self::assertStringContainsString('content', $body);
    }

    public function testCopyOfAMissingFileThrows(): void
    {
        $this->queueNotFound();

        $this->expectException(UnableToCopyFile::class);

        $this->makeAdapter()->copy('missing.txt', 'b.txt', new Config());
    }

    public function testMoveCopiesThenDeletesTheSource(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'a.txt', 'size' => 7]);
        $this->factory->queueResponse(200, 'content');
        $this->factory->queueJson(200, ['id' => 100]);
        $this->factory->queueResponse(204);

        $this->makeAdapter(trash: false)->move('a.txt', 'b.txt', new Config());

        self::assertSame([
            'GET ' . self::pathUri('a.txt'),
            'GET ' . self::API . '/file/99/download',
            'POST ' . self::API . '/file/upload',
            'DELETE ' . self::API . '/file/99?trash=0',
        ], $this->requestLines());
    }

    public function testMoveWrapsErrors(): void
    {
        $this->queueObject(['id' => 99, 'name' => 'a.txt', 'size' => 7]);
        $this->factory->queueResponse(200, 'content');
        $this->factory->queueResponse(500);

        $this->expectException(UnableToMoveFile::class);

        $this->makeAdapter()->move('a.txt', 'b.txt', new Config());
    }
}
