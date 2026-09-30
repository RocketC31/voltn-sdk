<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Http;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\AuthenticationException;
use RocketC31\Voltn\Exception\AuthorizationException;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\ServerException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\ExceptionFactory;

final class ExceptionFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{int, class-string}>
     */
    public static function statusCodeMappingProvider(): iterable
    {
        yield '401 -> AuthenticationException' => [401, AuthenticationException::class];
        yield '403 -> AuthorizationException' => [403, AuthorizationException::class];
        yield '404 -> NotFoundException' => [404, NotFoundException::class];
        yield '500 -> ServerException' => [500, ServerException::class];
        yield '503 -> ServerException' => [503, ServerException::class];
        yield '418 -> UnexpectedResponseException' => [418, UnexpectedResponseException::class];
        yield '400 -> UnexpectedResponseException' => [400, UnexpectedResponseException::class];
        yield '429 -> UnexpectedResponseException' => [429, UnexpectedResponseException::class];
    }

    #[DataProvider('statusCodeMappingProvider')]
    public function testFromResponseMapsStatusCodeToExceptionClass(int $statusCode, string $expectedClass): void
    {
        $response = new Response($statusCode, [], json_encode(['message' => 'oops']));

        $exception = ExceptionFactory::fromResponse($response);

        self::assertInstanceOf($expectedClass, $exception);
        self::assertSame($statusCode, $exception->getStatusCode());
        self::assertSame('oops', $exception->getApiMessage());
    }

    public function testFromResponseHandlesEmptyBody(): void
    {
        $response = new Response(500, [], '');

        $exception = ExceptionFactory::fromResponse($response);

        self::assertInstanceOf(ServerException::class, $exception);
        self::assertNull($exception->getJson());
        self::assertNull($exception->getApiMessage());
    }

    public function testFromResponseHandlesNonJsonBody(): void
    {
        $response = new Response(500, [], '<html>Internal Server Error</html>');

        $exception = ExceptionFactory::fromResponse($response);

        self::assertNull($exception->getJson());
        self::assertSame('<html>Internal Server Error</html>', $exception->getRawBody());
    }

    public function testFromResponseCapturesRequestAndResponse(): void
    {
        $request = new Request('GET', 'https://example.test/api/file/1');
        $response = new Response(404, [], json_encode(['error' => 'not found']));

        $exception = ExceptionFactory::fromResponse($response, $request);

        self::assertSame($request, $exception->getRequest());
        self::assertSame($response, $exception->getResponse());
    }
}
