<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Model\FileToken;
use RocketC31\Voltn\Model\FileTokenType;

final class FileTokenTest extends TestCase
{
    public function testFromArrayMapsFields(): void
    {
        $token = FileToken::fromArray(['token' => 'abc123'], FileTokenType::Preview);

        self::assertSame('abc123', $token->getToken());
        self::assertSame(FileTokenType::Preview, $token->getType());
    }

    public function testFromArrayHandlesEmptyArray(): void
    {
        $token = FileToken::fromArray([], FileTokenType::Download);

        self::assertSame('', $token->getToken());
    }
}
