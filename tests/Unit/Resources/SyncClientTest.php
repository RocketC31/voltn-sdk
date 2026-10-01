<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Resources;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\AuthorizationException;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\ObjectType;
use RocketC31\Voltn\Resources\SyncClient;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class SyncClientTest extends TestCase
{
    private function makeClient(MockTransportFactory $factory): SyncClient
    {
        $transport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
        );

        return new SyncClient($transport);
    }

    public function testFolderAtAddsTrailingSlashAndReturnsFolder(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['object' => ['id' => 1458, 'name' => '2026', 'parent_id' => 42]]);

        $folder = $this->makeClient($factory)->folderAt(7, '/backups/site web/2026');

        self::assertSame(1458, $folder->getId());
        self::assertSame('2026', $folder->getName());
        self::assertSame(
            'https://tenant.example.test/api/path?root=7&path=backups%2Fsite%20web%2F2026%2F',
            (string) $factory->getLastRequest()?->getUri(),
        );
    }

    public function testFolderAtWithEmptyPathTargetsTheRoot(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['id' => 7, 'name' => 'Racine']);

        $folder = $this->makeClient($factory)->folderAt(7, '');

        // GET /path answers 404 for "/": the root is read directly.
        self::assertSame(7, $folder->getId());
        self::assertSame('https://tenant.example.test/api/folder/7', (string) $factory->getLastRequest()?->getUri());
    }

    public function testFileAtStripsSlashesAndReturnsFile(): void
    {
        $factory = new MockTransportFactory();
        // Real platform shape: unwrapped object, parent folder in `parent_id`.
        $factory->queueJson(200, ['id' => 99, 'name' => 'é.zip', 'parent_id' => 1458, 'size' => 10, 'guid' => 'abc']);

        $file = $this->makeClient($factory)->fileAt(7, '/backups/é.zip');

        self::assertSame(99, $file->getId());
        self::assertSame(1458, $file->getFolderId());
        self::assertSame(
            'https://tenant.example.test/api/path?root=7&path=backups%2F%C3%A9.zip',
            (string) $factory->getLastRequest()?->getUri(),
        );
    }

    public function testFileAtRefusesAFolderMatchedWithoutTrailingSlash(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['id' => 11132, 'name' => 'Laravel', 'parent_id' => 11129, 'flags' => 0]);

        $this->expectException(NotFoundException::class);

        $this->makeClient($factory)->fileAt(7, 'Laravel');
    }

    public function testDocumentedWrappedShapeIsStillAccepted(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['object' => ['id' => 1458, 'name' => '2026']]);

        self::assertSame(1458, $this->makeClient($factory)->folderAt(7, '2026')->getId());
    }

    public function testFileAtRejectsAnEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeClient(new MockTransportFactory())->fileAt(7, '/');
    }

    public function testPathLookupPropagatesNotFound(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['error' => 'not found']);

        $this->expectException(NotFoundException::class);

        $this->makeClient($factory)->fileAt(7, 'missing.zip');
    }

    public function testPathLookupWithoutObjectIsUnexpected(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, []);

        $this->expectException(UnexpectedResponseException::class);

        $this->makeClient($factory)->folderAt(7, 'x');
    }

    public function testObjectPathReturnsThePath(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['object' => 'Documents/Partagé/informations_diverses.docx']);

        $path = $this->makeClient($factory)->objectPath(1, ObjectType::File, 123);

        self::assertSame('Documents/Partagé/informations_diverses.docx', $path);
        self::assertSame(
            'https://tenant.example.test/api/objectpath?root=1&object_type=Fichier&object_id=123',
            (string) $factory->getLastRequest()?->getUri(),
        );
    }

    public function testIsChildOfIsTrueOn200WithPlainTextBody(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(200, 'OK', ['Content-Type' => 'text/plain']);

        self::assertTrue($this->makeClient($factory)->isChildOf(12, 1));
        self::assertSame(
            'https://tenant.example.test/api/ischildof?root=1&folder=12',
            (string) $factory->getLastRequest()?->getUri(),
        );
    }

    public function testIsChildOfIsFalseOn404(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(404, 'Not a child');

        self::assertFalse($this->makeClient($factory)->isChildOf(500, 1));
    }

    public function testIsChildOfPropagatesForbidden(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(403, 'Forbidden');

        $this->expectException(AuthorizationException::class);

        $this->makeClient($factory)->isChildOf(12, 1);
    }

    public function testQuotasAreMapped(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(__DIR__ . '/../../Fixtures/Sync/quotas.json');

        $quotas = $this->makeClient($factory)->quotas(142);

        self::assertSame('https://tenant.example.test/api/quotas?folder=142', (string) $factory->getLastRequest()?->getUri());
        self::assertSame(10000000000, $quotas->getPlatform()->getQuota());
        self::assertSame(7952000000, $quotas->getPlatform()->getRemaining());
        self::assertNull($quotas->getUser()->getQuota());
        self::assertNull($quotas->getUser()->getRemaining());
        self::assertSame(589412556, $quotas->getUser()->getUsed());
        self::assertSame(142, $quotas->getFolder()?->getFolderId());
    }

    public function testQuotasWithoutFolder(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(200, ['platform' => ['quota' => null, 'used' => 1], 'user' => ['quota' => null, 'used' => 1]]);

        $quotas = $this->makeClient($factory)->quotas();

        self::assertSame('https://tenant.example.test/api/quotas', (string) $factory->getLastRequest()?->getUri());
        self::assertNull($quotas->getFolder());
    }
}
