<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Upload;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;
use RocketC31\Voltn\Upload\TusUploadManager;
use RocketC31\Voltn\Upload\UploadOptions;

final class TusUploadManagerTest extends TestCase
{
    private function makeManager(MockTransportFactory $factory): TusUploadManager
    {
        $moduleTransport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
        );

        $tusTransport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api/tus',
        );

        return new TusUploadManager($moduleTransport, $tusTransport, $factory->getRequestFactory(), $factory->getStreamFactory());
    }

    public function testUploadBootstrapsCreatesAndFinalizesSession(): void
    {
        $factory = new MockTransportFactory();

        // 1. Bootstrap: POST /file/tus
        $factory->queueJson(200, ['token' => 'session-abc']);
        // 2. TUS creation: POST /api/tus
        $factory->queueResponse(201, '', ['Location' => 'https://tenant.example.test/api/tus/upload-xyz']);
        // 3. TUS chunk: PATCH .../upload-xyz
        $factory->queueResponse(204, '', ['Upload-Offset' => '20']);
        // 4. Finalize: POST /file/tus {token}
        $factory->queueJson(200, ['id' => 55, 'name' => 'big-file.zip', 'size' => 20]);

        $manager = $this->makeManager($factory);

        $file = $manager->upload(1, 'big-file.zip', $factory->getStreamFactory()->createStream(str_repeat('a', 20)), 20);

        self::assertSame(55, $file->getId());
        self::assertSame(4, $factory->count());

        $requests = $factory->getRequestHistory();

        self::assertSame('POST', $requests[0]->getMethod());
        self::assertSame('https://tenant.example.test/api/file/tus', (string) $requests[0]->getUri());
        self::assertSame(
            ['name' => 'big-file.zip', 'target' => 1, 'size' => 20],
            json_decode((string) $requests[0]->getBody(), true),
        );

        self::assertSame('POST', $requests[1]->getMethod());
        self::assertSame('https://tenant.example.test/api/tus', (string) $requests[1]->getUri());
        self::assertSame('1.0.0', $requests[1]->getHeaderLine('Tus-Resumable'));
        self::assertSame('20', $requests[1]->getHeaderLine('Upload-Length'));
        self::assertStringContainsString('filename ' . base64_encode('big-file.zip'), $requests[1]->getHeaderLine('Upload-Metadata'));
        self::assertStringContainsString('token ' . base64_encode('session-abc'), $requests[1]->getHeaderLine('Upload-Metadata'));

        self::assertSame('PATCH', $requests[2]->getMethod());
        self::assertSame('https://tenant.example.test/api/tus/upload-xyz', (string) $requests[2]->getUri());
        self::assertSame('0', $requests[2]->getHeaderLine('Upload-Offset'));
        self::assertSame('application/offset+octet-stream', $requests[2]->getHeaderLine('Content-Type'));
        self::assertSame(str_repeat('a', 20), (string) $requests[2]->getBody());

        self::assertSame('POST', $requests[3]->getMethod());
        self::assertSame('https://tenant.example.test/api/file/tus', (string) $requests[3]->getUri());
        self::assertSame(['token' => 'session-abc'], json_decode((string) $requests[3]->getBody(), true));
    }

    public function testUploadSendsMultipleChunksWhenContentExceedsChunkSize(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['token' => 'session-abc']);
        $factory->queueResponse(201, '', ['Location' => '/upload-xyz']);
        $factory->queueResponse(204, '', ['Upload-Offset' => '10']);
        $factory->queueResponse(204, '', ['Upload-Offset' => '20']);
        $factory->queueJson(200, ['id' => 1]);

        $manager = $this->makeManager($factory);
        $progressCalls = [];
        $options = new UploadOptions(chunkSize: 10, onProgress: function (int $sent, int $total) use (&$progressCalls): void {
            $progressCalls[] = [$sent, $total];
        });

        $manager->upload(1, 'file.bin', $factory->getStreamFactory()->createStream(str_repeat('b', 20)), 20, $options);

        self::assertSame(5, $factory->count());
        self::assertSame([[10, 20], [20, 20]], $progressCalls);

        $requests = $factory->getRequestHistory();
        self::assertSame('0', $requests[2]->getHeaderLine('Upload-Offset'));
        self::assertSame('10', $requests[3]->getHeaderLine('Upload-Offset'));
    }

    public function testUploadResolvesRelativeLocationAgainstTusBaseUri(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['token' => 'session-abc']);
        $factory->queueResponse(201, '', ['Location' => '/relative-upload-id']);
        $factory->queueResponse(204, '', ['Upload-Offset' => '3']);
        $factory->queueJson(200, ['id' => 1]);

        $manager = $this->makeManager($factory);
        $manager->upload(1, 'a.txt', $factory->getStreamFactory()->createStream('abc'), 3);

        $requests = $factory->getRequestHistory();
        self::assertSame('https://tenant.example.test/api/tus/relative-upload-id', (string) $requests[2]->getUri());
    }

    public function testBootstrapThrowsWhenNoSessionTokenIsReturned(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['unexpected' => 'shape']);

        $manager = $this->makeManager($factory);

        $this->expectException(UnexpectedResponseException::class);
        $manager->upload(1, 'a.txt', $factory->getStreamFactory()->createStream('abc'), 3);
    }

    public function testCreateThrowsWhenLocationHeaderMissing(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['token' => 'session-abc']);
        $factory->queueResponse(201, '');

        $manager = $this->makeManager($factory);

        $this->expectException(UnexpectedResponseException::class);
        $manager->upload(1, 'a.txt', $factory->getStreamFactory()->createStream('abc'), 3);
    }

    public function testGetUploadOffsetSendsHeadRequest(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '', ['Upload-Offset' => '42']);

        $manager = $this->makeManager($factory);
        $offset = $manager->getUploadOffset('https://tenant.example.test/api/tus/upload-xyz');

        self::assertSame(42, $offset);
        self::assertSame('HEAD', $factory->getLastRequest()->getMethod());
        self::assertSame('1.0.0', $factory->getLastRequest()->getHeaderLine('Tus-Resumable'));
    }

    public function testGetUploadOffsetThrowsWhenHeaderMissing(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '');

        $manager = $this->makeManager($factory);

        $this->expectException(UnexpectedResponseException::class);
        $manager->getUploadOffset('https://tenant.example.test/api/tus/upload-xyz');
    }

    public function testResumeSeeksToCurrentOffsetAndContinues(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, '', ['Upload-Offset' => '10']); // HEAD
        $factory->queueResponse(204, '', ['Upload-Offset' => '20']); // PATCH remaining
        $factory->queueJson(200, ['id' => 1]); // finalize

        $manager = $this->makeManager($factory);
        $content = $factory->getStreamFactory()->createStream(str_repeat('x', 20));

        $manager->resume('session-abc', 'https://tenant.example.test/api/tus/upload-xyz', $content, 20);

        $requests = $factory->getRequestHistory();
        self::assertSame('HEAD', $requests[0]->getMethod());
        self::assertSame('PATCH', $requests[1]->getMethod());
        self::assertSame('10', $requests[1]->getHeaderLine('Upload-Offset'));
        self::assertSame(str_repeat('x', 10), (string) $requests[1]->getBody());
    }
}
