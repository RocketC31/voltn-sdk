<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Http\HttpTransport;
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

    public function testCreateFileTokenReturnsDownloadToken(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/download_token.json');

        $token = $this->makeClient($factory)->createFileToken(123);

        self::assertSame('dl-abc123', $token->getToken());
        self::assertSame('https://tenant.example.test/download/dl-abc123', $token->getUrl());

        $request = $factory->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://tenant.example.test/api/token/file/123/download',
            (string) $request->getUri(),
        );
    }

    public function testCreateFileTokenWithCustomType(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/download_token.json');

        $this->makeClient($factory)->createFileToken(123, 'preview');

        self::assertSame(
            'https://tenant.example.test/api/token/file/123/preview',
            (string) $factory->getLastRequest()->getUri(),
        );
    }
}
