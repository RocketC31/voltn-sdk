<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Resources\FileClient;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class FileClientTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Files';

    private function makeClient(MockTransportFactory $factory): FileClient
    {
        $transport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
        );

        return new FileClient($transport);
    }

    public function testGetReturnsFile(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file.json');

        $file = $this->makeClient($factory)->get(123);

        self::assertSame(123, $file->getId());
        self::assertSame('invoice.pdf', $file->getName());
        self::assertSame(45678, $file->size());
        self::assertFalse($file->isLocked());
    }

    public function testGetReturnsLockedFile(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file_locked.json');

        $file = $this->makeClient($factory)->get(124);

        self::assertTrue($file->isLocked());
        self::assertSame(5, $file->getLock()?->getUserId());
        self::assertFalse($file->canWrite());
    }

    public function testInfosCallsInfosEndpoint(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file.json');

        $this->makeClient($factory)->infos(123);

        self::assertSame(
            'https://tenant.example.test/api/file/123/infos',
            (string) $factory->getLastRequest()->getUri(),
        );
    }

    public function testDownloadReturnsStreamContent(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, 'the-file-bytes');

        $stream = $this->makeClient($factory)->download(123);

        self::assertSame('the-file-bytes', (string) $stream);
        self::assertSame(
            'https://tenant.example.test/api/file/123/download',
            (string) $factory->getLastRequest()->getUri(),
        );
    }

    public function testDownloadThrowsNotFoundExceptionOn404(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'not found']);

        $this->expectException(NotFoundException::class);
        $this->makeClient($factory)->download(999);
    }

    public function testUploadSendsMultipartRequestAndReturnsFile(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file.json', 201);

        $content = $factory->getStreamFactory()->createStream('pdf-bytes');
        $file = $this->makeClient($factory)->upload(42, 'invoice.pdf', $content, 'application/pdf');

        self::assertSame(123, $file->getId());

        $request = $factory->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://tenant.example.test/api/file/upload',
            (string) $request->getUri(),
        );
        self::assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="folderId"', $body);
        self::assertStringContainsString('name="targetFile"; filename="invoice.pdf"', $body);
        self::assertStringContainsString('pdf-bytes', $body);
    }

    public function testReplaceContentSendsRawBodyWithContentType(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file.json');

        $content = $factory->getStreamFactory()->createStream('new-bytes');
        $file = $this->makeClient($factory)->replaceContent(123, $content, 'application/pdf');

        self::assertSame(123, $file->getId());

        $request = $factory->getLastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame(
            'https://tenant.example.test/api/file/123/upload',
            (string) $request->getUri(),
        );
        self::assertSame('application/pdf', $request->getHeaderLine('Content-Type'));
        self::assertSame('new-bytes', (string) $request->getBody());
    }

    public function testUpdateSendsPutWithChanges(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file.json');

        $this->makeClient($factory)->update(123, ['name' => 'renamed.pdf']);

        $request = $factory->getLastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame(['name' => 'renamed.pdf'], json_decode((string) $request->getBody(), true));
    }

    public function testDeleteSendsDeleteRequest(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(204, '');

        $this->makeClient($factory)->delete(123);

        $request = $factory->getLastRequest();
        self::assertSame('DELETE', $request->getMethod());
    }

    public function testExistsReturnsTrueWhenFileFound(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file.json');

        self::assertTrue($this->makeClient($factory)->exists(123));
    }

    public function testExistsReturnsFalseOn404(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'not found']);

        self::assertFalse($this->makeClient($factory)->exists(999));
    }
}
