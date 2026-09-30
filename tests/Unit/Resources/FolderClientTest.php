<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Exception\AuthorizationException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Resources\FolderClient;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class FolderClientTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Folders';

    private function makeClient(MockTransportFactory $factory): FolderClient
    {
        $transport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
        );

        return new FolderClient($transport);
    }

    public function testGetReturnsFolder(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folder.json');

        $folder = $this->makeClient($factory)->get(42);

        self::assertSame(42, $folder->getId());
        self::assertSame('Invoices', $folder->getName());
    }

    public function testGetSendsDepthAndFullQueryParameters(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folder.json');

        $this->makeClient($factory)->get(42, depth: 2, full: true);

        $request = $factory->getLastRequest();
        self::assertSame(
            'https://tenant.example.test/api/folder/42?depth=2&full=true',
            (string) $request->getUri(),
        );
    }

    public function testGetWithoutOptionsOmitsQueryString(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folder.json');

        $this->makeClient($factory)->get(42);

        $request = $factory->getLastRequest();
        self::assertSame('https://tenant.example.test/api/folder/42', (string) $request->getUri());
    }

    public function testListRootsReturnsFolders(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folders_list.json');

        $folders = $this->makeClient($factory)->listRoots();

        self::assertCount(2, $folders);
        self::assertSame('Personal', $folders[0]->getName());
        self::assertSame('Shared', $folders[1]->getName());
    }

    public function testCreateSendsExpectedBodyAndReturnsFolder(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folder_created.json', 201);

        $folder = $this->makeClient($factory)->create(1, 'New Folder');

        self::assertSame(99, $folder->getId());
        self::assertSame('New Folder', $folder->getName());

        $request = $factory->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            ['parent_id' => 1, 'name' => 'New Folder'],
            json_decode((string) $request->getBody(), true),
        );
    }

    public function testUpdateSendsPutWithChanges(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folder_created.json');

        $this->makeClient($factory)->update(99, ['name' => 'Renamed']);

        $request = $factory->getLastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('https://tenant.example.test/api/folder/99', (string) $request->getUri());
        self::assertSame(['name' => 'Renamed'], json_decode((string) $request->getBody(), true));
    }

    public function testDeleteSendsDeleteRequest(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueResponse(204, '');

        $this->makeClient($factory)->delete(99);

        $request = $factory->getLastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('https://tenant.example.test/api/folder/99', (string) $request->getUri());
    }

    public function testExistsReturnsTrueWhenFolderFound(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/folder.json');

        self::assertTrue($this->makeClient($factory)->exists(42));
    }

    public function testExistsReturnsFalseOn404(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(404, ['message' => 'not found']);

        self::assertFalse($this->makeClient($factory)->exists(999));
    }

    public function testExistsPropagatesOtherErrors(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJson(403, ['message' => 'forbidden']);

        $this->expectException(AuthorizationException::class);
        $this->makeClient($factory)->exists(42);
    }
}
