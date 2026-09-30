<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Client;
use RocketC31\Voltn\ClientBuilder;

/**
 * Base class for opt-in integration tests that hit a real Voltn
 * tenant. These are never run as part of the default test suite/CI job —
 * they are skipped whenever the required environment variables are not
 * set, and only meant to be run manually against a real (typically
 * sandbox) Voltn tenant:
 *
 * ```
 * VOLTN_TEST_BASE_URI=https://tenant.voltn.example \
 * VOLTN_TEST_CLIENT_ID=... \
 * VOLTN_TEST_CLIENT_SECRET=... \
 * vendor/bin/phpunit --testsuite=integration
 * ```
 */
abstract class AbstractIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        foreach (['VOLTN_TEST_BASE_URI', 'VOLTN_TEST_CLIENT_ID', 'VOLTN_TEST_CLIENT_SECRET'] as $variable) {
            if (getenv($variable) === false) {
                self::markTestSkipped(sprintf(
                    'Integration tests are opt-in and require the %s environment variable to be set.',
                    $variable,
                ));
            }
        }
    }

    protected function buildClient(): Client
    {
        $baseUri = (string) getenv('VOLTN_TEST_BASE_URI');
        $clientId = (string) getenv('VOLTN_TEST_CLIENT_ID');
        $clientSecret = (string) getenv('VOLTN_TEST_CLIENT_SECRET');

        return ClientBuilder::create($baseUri)
            ->withClientCredentials($clientId, $clientSecret)
            ->build();
    }
}
