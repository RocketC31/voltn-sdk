<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Model\RootSpace;

final class RootSpaceTest extends TestCase
{
    public function testFromArrayMapsFields(): void
    {
        $root = RootSpace::fromArray(['id' => 1, 'type' => 'personal', 'name' => 'My Space']);

        self::assertSame(1, $root->getId());
        self::assertSame('personal', $root->getType());
        self::assertSame('My Space', $root->getName());
        self::assertNull($root->getFolder());
    }

    public function testFromArrayWithEmbeddedFolder(): void
    {
        $root = RootSpace::fromArray([
            'id' => 1,
            'type' => 'shared',
            'folder' => ['id' => 99, 'name' => 'Shared'],
        ]);

        self::assertNotNull($root->getFolder());
        self::assertSame(99, $root->getFolder()?->getId());
    }

    public function testFromArrayHandlesEmptyArray(): void
    {
        $root = RootSpace::fromArray([]);

        self::assertNull($root->getId());
        self::assertNull($root->getType());
        self::assertNull($root->getName());
        self::assertNull($root->getFolder());
    }
}
