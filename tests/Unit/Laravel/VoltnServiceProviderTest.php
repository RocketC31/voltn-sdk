<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\Flysystem\VoltnAdapter;
use RocketC31\Voltn\Laravel\CacheTokenStorage;
use RocketC31\Voltn\Laravel\VoltnServiceProvider;

final class VoltnServiceProviderTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VoltnServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('filesystems.disks.voltn', self::diskConfig());
    }

    /**
     * @return array<string, mixed>
     */
    private static function diskConfig(): array
    {
        return [
            'driver' => 'voltn',
            'base_uri' => 'https://tenant.example.test',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'root' => '42',
            'trash' => false,
        ];
    }

    private function makeStorage(): CacheTokenStorage
    {
        $cache = $this->app?->make('cache.store');
        $encrypter = $this->app?->make('encrypter');
        self::assertInstanceOf(Repository::class, $cache);
        self::assertInstanceOf(StringEncrypter::class, $encrypter);

        return new CacheTokenStorage($cache, $encrypter, CacheTokenStorage::keyFor('https://tenant.example.test', 'client-id'));
    }

    private function cache(): Repository
    {
        $cache = $this->app?->make('cache.store');
        self::assertInstanceOf(Repository::class, $cache);

        return $cache;
    }

    public function testTheProviderIsRegistered(): void
    {
        self::assertNotNull($this->app?->getProvider(VoltnServiceProvider::class));
    }

    public function testAConfiguredDiskUsesTheVoltnAdapter(): void
    {
        $disk = Storage::disk('voltn');

        self::assertInstanceOf(FilesystemAdapter::class, $disk);
        self::assertInstanceOf(VoltnAdapter::class, $disk->getAdapter());
    }

    public function testAnOnDemandDiskUsesTheVoltnAdapter(): void
    {
        $disk = Storage::build(self::diskConfig());

        self::assertInstanceOf(FilesystemAdapter::class, $disk);
        self::assertInstanceOf(VoltnAdapter::class, $disk->getAdapter());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredOptionProvider(): iterable
    {
        yield 'base_uri' => ['base_uri'];
        yield 'client_id' => ['client_id'];
        yield 'client_secret' => ['client_secret'];
        yield 'root' => ['root'];
    }

    #[DataProvider('requiredOptionProvider')]
    public function testMissingRequiredOptionsThrow(string $option): void
    {
        $config = self::diskConfig();
        $config[$option] = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $option));

        Storage::build($config);
    }

    public function testInvalidOptionalOptionsThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Storage::build(['chunk_size' => 'lots'] + self::diskConfig());
    }

    public function testTheTokenStorageKeyDependsOnTenantAndClient(): void
    {
        $key = CacheTokenStorage::keyFor('https://tenant.example.test', 'client-id');

        self::assertSame('voltn:token:' . sha1('https://tenant.example.test|client-id'), $key);
        self::assertNotSame($key, CacheTokenStorage::keyFor('https://other.example.test', 'client-id'));
    }

    public function testTokenRoundTripIsEncryptedAtRest(): void
    {
        $storage = $this->makeStorage();
        $token = AccessToken::fromTokenResponse([
            'access_token' => 'super-secret-access-token',
            'refresh_token' => 'super-secret-refresh-token',
            'expires_in' => 3600,
        ]);

        $storage->set($token);

        $raw = $this->cache()->get($storage->getKey());
        self::assertIsString($raw);
        self::assertStringNotContainsString('super-secret-access-token', $raw);
        self::assertStringNotContainsString('super-secret-refresh-token', $raw);

        $restored = $storage->get();
        self::assertNotNull($restored);
        self::assertSame('super-secret-access-token', $restored->getAccessToken());
        self::assertSame('super-secret-refresh-token', $restored->getRefreshToken());
        self::assertEquals($token->getExpiresAt()?->getTimestamp(), $restored->getExpiresAt()?->getTimestamp());

        $storage->clear();
        self::assertNull($storage->get());
        self::assertNull($this->cache()->get($storage->getKey()));
    }

    public function testTheCacheEntryExpiresOneMinuteBeforeTheToken(): void
    {
        $storage = $this->makeStorage();
        $storage->set(AccessToken::fromTokenResponse(['access_token' => 't', 'expires_in' => 3600]));

        $this->travel(3530)->seconds();
        self::assertNotNull($this->cache()->get($storage->getKey()));

        $this->travel(20)->seconds();
        self::assertNull($this->cache()->get($storage->getKey()));
    }

    public function testANonExpiringTokenIsKeptTwentyThreeHours(): void
    {
        $storage = $this->makeStorage();
        $storage->set(AccessToken::fromTokenResponse(['access_token' => 't']));

        $this->travel(82700)->seconds();
        self::assertNotNull($storage->get());

        $this->travel(200)->seconds();
        self::assertNull($storage->get());
    }

    public function testATokenAboutToExpireIsNotStored(): void
    {
        $storage = $this->makeStorage();
        $storage->set(AccessToken::fromTokenResponse(['access_token' => 'previous', 'expires_in' => 3600]));

        $storage->set(AccessToken::fromTokenResponse(['access_token' => 't', 'expires_in' => 30]));

        self::assertNull($this->cache()->get($storage->getKey()));
    }

    public function testAnExpiredTokenIsNotReturned(): void
    {
        $storage = $this->makeStorage();
        $encrypter = $this->app?->make('encrypter');
        self::assertInstanceOf(StringEncrypter::class, $encrypter);

        $expired = AccessToken::fromArray(['access_token' => 't', 'expires_at' => '2020-01-01T00:00:00+00:00']);
        $this->cache()->put($storage->getKey(), $encrypter->encryptString(json_encode($expired->toArray(), JSON_THROW_ON_ERROR)), 3600);

        self::assertNull($storage->get());
    }

    public function testACorruptedValueIsTreatedAsAbsent(): void
    {
        $storage = $this->makeStorage();
        $encrypter = $this->app?->make('encrypter');
        self::assertInstanceOf(StringEncrypter::class, $encrypter);

        $this->cache()->put($storage->getKey(), 'not-an-encrypted-payload', 3600);
        self::assertNull($storage->get());

        $this->cache()->put($storage->getKey(), $encrypter->encryptString('{not json'), 3600);
        self::assertNull($storage->get());

        $this->cache()->put($storage->getKey(), ['unexpected'], 3600);
        self::assertNull($storage->get());
    }
}
