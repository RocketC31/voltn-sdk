<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Integration;

/**
 * A minimal smoke test exercising the client_credentials grant and a basic
 * read call against a real Voltn tenant. See
 * {@see AbstractIntegrationTestCase} for how to opt into running this.
 */
final class ClientCredentialsSmokeTest extends AbstractIntegrationTestCase
{
    public function testCanAuthenticateAndListRootSpaces(): void
    {
        $client = $this->buildClient();

        $roots = $client->roots()->list();

        self::assertNotNull($client->getCurrentAccessToken());
        self::assertIsArray($roots);
    }
}
