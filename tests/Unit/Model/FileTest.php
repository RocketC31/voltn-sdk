<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Model\File;

final class FileTest extends TestCase
{
    public function testFromArrayMapsAllFields(): void
    {
        $file = File::fromArray([
            'id' => 42,
            'name' => 'invoice.pdf',
            'folder_id' => 7,
            'size' => 12345,
            'hash' => 'd41d8cd98f00b204e9800998ecf8427e',
            'file_type' => 'document',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'last_modified' => '2026-02-01T00:00:00+00:00',
            'lock' => null,
            'can_read' => true,
            'can_write' => true,
            'can_delete' => false,
            'can_share' => true,
        ]);

        self::assertSame(42, $file->getId());
        self::assertSame('invoice.pdf', $file->getName());
        self::assertSame(7, $file->getFolderId());
        self::assertSame(12345, $file->size());
        self::assertSame('d41d8cd98f00b204e9800998ecf8427e', $file->hash());
        self::assertSame('document', $file->getFileType());
        self::assertNotNull($file->getCreatedAt());
        self::assertNotNull($file->lastModified());
        self::assertNull($file->getLock());
        self::assertFalse($file->isLocked());
        self::assertTrue($file->canRead());
        self::assertTrue($file->canWrite());
        self::assertFalse($file->canDelete());
        self::assertTrue($file->canShare());
        self::assertSame([], $file->getVersions());
    }

    public function testFromArrayHandlesNullLockAsNoLock(): void
    {
        $file = File::fromArray(['id' => 1, 'name' => 'a.txt', 'lock' => null]);

        self::assertNull($file->getLock());
        self::assertFalse($file->isLocked());
    }

    public function testFromArrayHandlesLockObject(): void
    {
        $file = File::fromArray([
            'id' => 1,
            'name' => 'a.txt',
            'lock' => ['user_id' => 5, 'user_name' => 'Jane Doe', 'locked_at' => '2026-01-01T00:00:00+00:00'],
        ]);

        self::assertTrue($file->isLocked());
        self::assertSame(5, $file->getLock()?->getUserId());
        self::assertSame('Jane Doe', $file->getLock()?->getUserName());
        self::assertNotNull($file->getLock()?->getLockedAt());
    }

    public function testFromArrayWithCompletelyEmptyArrayDoesNotThrow(): void
    {
        $file = File::fromArray([]);

        self::assertSame(0, $file->getId());
        self::assertSame('', $file->getName());
        self::assertNull($file->getFolderId());
        self::assertNull($file->size());
        self::assertNull($file->hash());
        self::assertNull($file->getFileType());
        self::assertNull($file->getCreatedAt());
        self::assertNull($file->lastModified());
        self::assertNull($file->getLock());
        self::assertTrue($file->canRead());
        self::assertFalse($file->canWrite());
        self::assertFalse($file->canDelete());
        self::assertFalse($file->canShare());
        self::assertSame([], $file->getVersions());
    }

    public function testFromArrayIgnoresMissingSizeAndHash(): void
    {
        $file = File::fromArray(['id' => 1, 'name' => 'a.txt', 'size' => null, 'hash' => null]);

        self::assertNull($file->size());
        self::assertNull($file->hash());
    }

    public function testFromArrayWithMalformedDateDoesNotThrow(): void
    {
        $file = File::fromArray(['id' => 1, 'name' => 'a.txt', 'created_at' => 'not-a-date']);

        self::assertNull($file->getCreatedAt());
    }

    public function testFromArrayMapsVersions(): void
    {
        $file = File::fromArray([
            'id' => 1,
            'name' => 'a.txt',
            'versions' => [
                ['id' => 10, 'version' => 1, 'size' => 100],
                ['id' => 11, 'version' => 2, 'size' => 200],
            ],
        ]);

        self::assertCount(2, $file->getVersions());
        self::assertSame(1, $file->getVersions()[0]->getVersionNumber());
        self::assertSame(200, $file->getVersions()[1]->size());
    }

    public function testFromArrayIgnoresNonArrayVersionEntries(): void
    {
        $file = File::fromArray(['id' => 1, 'name' => 'a.txt', 'versions' => ['not-an-array', 42]]);

        self::assertCount(2, $file->getVersions());
        self::assertNull($file->getVersions()[0]->getId());
    }
}
