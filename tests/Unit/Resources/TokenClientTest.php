<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\FileTokenType;
use RocketC31\Voltn\Resources\TokenClient;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class TokenClientTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Tokens';

    private function makeClient(MockTransportFactory $factory): TokenClient
    {
        $transport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
        );

        return new TokenClient($transport);
    }

    public function testCreateFileTokenDefaultsToDownload(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file_token.json', 201);

        $token = $this->makeClient($factory)->createFileToken(123);

        self::assertSame('eyJhbGciOiJBMjU2S1ciLCJlbm...', $token->getToken());
        self::assertSame(FileTokenType::Download, $token->getType());

        $request = $factory->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://tenant.example.test/api/token/file/123/download', (string) $request->getUri());
    }

    public function testCreateFileTokenWithExplicitType(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/file_token.json', 201);

        $token = $this->makeClient($factory)->createFileToken(123, FileTokenType::Edit);

        self::assertSame(FileTokenType::Edit, $token->getType());
        self::assertSame(
            'https://tenant.example.test/api/token/file/123/edit',
            (string) $factory->getLastRequest()?->getUri(),
        );
    }

    public function testCreateFileTokenThrowsWhenResponseHasNoToken(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(201, []);

        $this->expectException(UnexpectedResponseException::class);

        $this->makeClient($factory)->createFileToken(123);
    }

    public function testCreateFileTokenPropagatesNotFound(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'Not found']);

        $this->expectException(NotFoundException::class);

        $this->makeClient($factory)->createFileToken(999);
    }

    public function testPreviewUrlRequestsPreviewTokenAndBuildsIframeUrl(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(201, ['token' => 'prev/tok+en=']);

        $url = $this->makeClient($factory)->previewUrl(42);

        self::assertSame(
            'https://tenant.example.test/api/token/file/42/preview',
            (string) $factory->getLastRequest()?->getUri(),
        );
        self::assertSame('https://tenant.example.test/api/preview/42?token=prev%2Ftok%2Ben%3D', $url);
        self::assertCount(1, $factory->getRequestHistory());
    }

    public function testDownloadUrlRequestsDownloadTokenAndBuildsDownloadUrl(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(201, ['token' => 'dl-token']);

        $url = $this->makeClient($factory)->downloadUrl(42);

        self::assertSame(
            'https://tenant.example.test/api/token/file/42/download',
            (string) $factory->getLastRequest()?->getUri(),
        );
        self::assertSame('https://tenant.example.test/api/file/download?token=dl-token', $url);
    }
}
