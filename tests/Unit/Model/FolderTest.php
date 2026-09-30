<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Model\Folder;

final class FolderTest extends TestCase
{
    public function testFromArrayMapsBasicFields(): void
    {
        $folder = Folder::fromArray([
            'id' => 1,
            'name' => 'Invoices',
            'parent_id' => null,
            'path' => '/Invoices',
            'can_read' => true,
            'can_write' => true,
        ]);

        self::assertSame(1, $folder->getId());
        self::assertSame('Invoices', $folder->getName());
        self::assertNull($folder->getParentId());
        self::assertTrue($folder->isRoot());
        self::assertSame('/Invoices', $folder->getPath());
        self::assertSame([], $folder->getFiles());
        self::assertSame([], $folder->getFolders());
    }

    public function testFromArrayWithoutContentReturnsEmptyLists(): void
    {
        $folder = Folder::fromArray(['id' => 1, 'name' => 'Root']);

        self::assertSame([], $folder->getFiles());
        self::assertSame([], $folder->getFolders());
    }

    public function testFromArrayPopulatesNestedContentWhenPresent(): void
    {
        $folder = Folder::fromArray([
            'id' => 1,
            'name' => 'Root',
            'content' => [
                'files' => [
                    ['id' => 10, 'name' => 'a.txt'],
                ],
                'folders' => [
                    ['id' => 2, 'name' => 'Sub', 'parent_id' => 1],
                ],
            ],
        ]);

        self::assertCount(1, $folder->getFiles());
        self::assertSame('a.txt', $folder->getFiles()[0]->getName());
        self::assertCount(1, $folder->getFolders());
        self::assertSame('Sub', $folder->getFolders()[0]->getName());
        self::assertFalse($folder->getFolders()[0]->isRoot());
    }

    public function testFromArrayHandlesEmptyArray(): void
    {
        $folder = Folder::fromArray([]);

        self::assertSame(0, $folder->getId());
        self::assertSame('', $folder->getName());
        self::assertNull($folder->getParentId());
        self::assertTrue($folder->isRoot());
        self::assertNull($folder->getPath());
        self::assertNull($folder->getCreatedAt());
        self::assertNull($folder->getUpdatedAt());
        self::assertTrue($folder->canRead());
        self::assertFalse($folder->canWrite());
    }

    public function testFromArrayIgnoresNonArrayContent(): void
    {
        $folder = Folder::fromArray(['id' => 1, 'name' => 'Root', 'content' => 'not-an-array']);

        self::assertSame([], $folder->getFiles());
        self::assertSame([], $folder->getFolders());
    }
}
