<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Resources\RootClient;
use RocketC31\Voltn\Tests\Support\MockTransportFactory;

final class RootClientTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../Fixtures/Roots';

    private function makeClient(MockTransportFactory $factory): RootClient
    {
        $transport = new HttpTransport(
            $factory->getClient(),
            $factory->getRequestFactory(),
            $factory->getStreamFactory(),
            'https://tenant.example.test/api',
        );

        return new RootClient($transport);
    }

    public function testListReturnsRootSpaces(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/roots_list.json');

        $roots = $this->makeClient($factory)->list();

        self::assertCount(2, $roots);
        self::assertSame('personal', $roots[0]->getType());
        self::assertSame('shared', $roots[1]->getType());

        self::assertSame(
            'https://tenant.example.test/api/roots',
            (string) $factory->getLastRequest()->getUri(),
        );
    }

    public function testGetReturnsSingleRootSpace(): void
    {
        $factory = new MockTransportFactory();
        $factory->queueJsonFixture(self::FIXTURES_DIR . '/root.json');

        $root = $this->makeClient($factory)->get('personal');

        self::assertSame('personal', $root->getType());
        self::assertSame(
            'https://tenant.example.test/api/root/personal',
            (string) $factory->getLastRequest()->getUri(),
        );
    }
}
