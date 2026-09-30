<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\VoltnException;

final class VoltnExceptionTest extends TestCase
{
    public function testJsonAndApiMessageAreNullWhenBodyIsMissing(): void
    {
        $exception = new VoltnException('boom', 500, null, null, null);

        self::assertNull($exception->getJson());
        self::assertNull($exception->getApiMessage());
        self::assertNull($exception->getRawBody());
    }

    public function testJsonAndApiMessageAreNullWhenBodyIsEmptyString(): void
    {
        $exception = new VoltnException('boom', 500, null, null, '');

        self::assertNull($exception->getJson());
        self::assertNull($exception->getApiMessage());
    }

    public function testJsonAndApiMessageAreNullWhenBodyIsNotValidJson(): void
    {
        $exception = new VoltnException('boom', 500, null, null, 'not json at all {{{');

        self::assertNull($exception->getJson());
        self::assertNull($exception->getApiMessage());
        self::assertSame('not json at all {{{', $exception->getRawBody());
    }

    public function testJsonIsNullWhenBodyIsAJsonScalarOrList(): void
    {
        $exception = new VoltnException('boom', 500, null, null, '"just a string"');
        self::assertNull($exception->getJson());

        $exception = new VoltnException('boom', 500, null, null, '[1, 2, 3]');
        self::assertSame([1, 2, 3], $exception->getJson());
        self::assertNull($exception->getApiMessage());
    }

    public function testGetJsonDecodesObjectBody(): void
    {
        $exception = new VoltnException('boom', 400, null, null, '{"message": "Invalid request"}');

        self::assertSame(['message' => 'Invalid request'], $exception->getJson());
    }

    #[DataProvider('apiMessageKeyProvider')]
    public function testGetApiMessageProbesPlausibleKeys(string $key): void
    {
        $exception = new VoltnException('boom', 400, null, null, json_encode([$key => 'Something went wrong']));

        self::assertSame('Something went wrong', $exception->getApiMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function apiMessageKeyProvider(): iterable
    {
        yield 'message' => ['message'];
        yield 'error_description' => ['error_description'];
        yield 'error' => ['error'];
        yield 'detail' => ['detail'];
        yield 'title' => ['title'];
    }

    public function testGetApiMessageIgnoresNonStringOrEmptyValues(): void
    {
        $exception = new VoltnException('boom', 400, null, null, json_encode([
            'message' => '',
            'error' => 42,
            'detail' => 'The real message',
        ]));

        self::assertSame('The real message', $exception->getApiMessage());
    }

    public function testStatusCodeAndRawBodyAreExposed(): void
    {
        $exception = new VoltnException('boom', 404, null, null, '{"error":"not found"}');

        self::assertSame(404, $exception->getStatusCode());
        self::assertSame('{"error":"not found"}', $exception->getRawBody());
        self::assertSame('boom', $exception->getMessage());
    }
}
