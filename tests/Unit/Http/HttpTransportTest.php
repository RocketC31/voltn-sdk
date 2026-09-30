<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\TransportException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Http\MultipartFilePart;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class HttpTransportTest extends TestCase
{
    private function makeTransport(MockTransportFactory $factory, array $defaultHeaders = []): HttpTransport
    {
        return new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
            $defaultHeaders,
        );
    }

    public function testSendForJsonDecodesSuccessfulResponse(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['id' => 1, 'name' => 'Invoices']);

        $transport = $this->makeTransport($factory);
        $result = $transport->sendForJson('GET', '/folder/1');

        self::assertSame(['id' => 1, 'name' => 'Invoices'], $result);
    }

    public function testSendForJsonReturnsNullForEmptyBody(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(204, '');

        $transport = $this->makeTransport($factory);
        $result = $transport->sendForJson('DELETE', '/folder/1');

        self::assertNull($result);
    }

    public function testSendForJsonThrowsUnexpectedResponseExceptionOnInvalidJson(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, 'not json', ['Content-Type' => 'application/json']);

        $transport = $this->makeTransport($factory);

        $this->expectException(UnexpectedResponseException::class);
        $transport->sendForJson('GET', '/folder/1');
    }

    public function testSendForJsonThrowsMappedExceptionOnErrorStatus(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'Folder not found']);

        $transport = $this->makeTransport($factory);

        $this->expectException(NotFoundException::class);
        $transport->sendForJson('GET', '/folder/999');
    }

    public function testSendForJsonBuildsCorrectUriWithQueryParameters(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['id' => 1]);

        $transport = $this->makeTransport($factory);
        $transport->sendForJson('GET', '/folder/1', ['depth' => 2, 'full' => 'true']);

        $request = $factory->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('https://tenant.example.test/api/folder/1?depth=2&full=true', (string) $request->getUri());
    }

    public function testSendForJsonSendsJsonBodyAndContentType(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(201, ['id' => 5]);

        $transport = $this->makeTransport($factory);
        $transport->sendForJson('POST', '/folder', null, ['name' => 'New Folder', 'parent_id' => 1]);

        $request = $factory->getLastRequest();
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            json_encode(['name' => 'New Folder', 'parent_id' => 1]),
            (string) $request->getBody(),
        );
    }

    public function testDefaultHeadersAreAppliedToEveryRequest(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, []);

        $transport = $this->makeTransport($factory, ['Authorization' => 'Bearer token123', 'As' => '42']);
        $transport->sendForJson('GET', '/folders');

        $request = $factory->getLastRequest();
        self::assertSame('Bearer token123', $request->getHeaderLine('Authorization'));
        self::assertSame('42', $request->getHeaderLine('As'));
    }

    public function testSendForStreamReturnsBodyStreamOnSuccess(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, 'binary file content');

        $transport = $this->makeTransport($factory);
        $stream = $transport->sendForStream('GET', '/file/1/download');

        self::assertSame('binary file content', (string) $stream);
    }

    public function testSendForStreamThrowsMappedExceptionOnErrorStatus(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'File not found']);

        $transport = $this->makeTransport($factory);

        $this->expectException(NotFoundException::class);
        $transport->sendForStream('GET', '/file/1/download');
    }

    public function testSendMultipartBuildsBodyWithFieldsAndFileWithoutBufferingFileContent(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(201, ['id' => 7]);

        $transport = $this->makeTransport($factory);
        $fileStream = $factory->getStreamFactory()->createStream('file-bytes-here');

        $result = $transport->sendMultipart(
            'POST',
            '/file/upload',
            ['folderId' => 1],
            [new MultipartFilePart('targetFile', 'invoice.pdf', $fileStream, 'application/pdf')],
        );

        self::assertSame(['id' => 7], $result);

        $request = $factory->getLastRequest();
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));

        $body = (string) $request->getBody();
        self::assertStringContainsString('name="folderId"', $body);
        self::assertStringContainsString('1', $body);
        self::assertStringContainsString('name="targetFile"; filename="invoice.pdf"', $body);
        self::assertStringContainsString('Content-Type: application/pdf', $body);
        self::assertStringContainsString('file-bytes-here', $body);
    }

    public function testTransportExceptionWrapsPsr18ClientException(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueException(new ConnectException('Connection refused', new GuzzleRequest('GET', 'https://tenant.example.test/api/folder/1')));

        $transport = $this->makeTransport($factory);

        $this->expectException(TransportException::class);
        $transport->sendForJson('GET', '/folder/1');
    }
}
