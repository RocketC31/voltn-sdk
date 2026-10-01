<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use RocketC31\Voltn\ClientBuilder;
use RocketC31\Voltn\Flysystem\VoltnAdapter;

/**
 * Registers the `voltn` filesystem driver:
 *
 * ```php
 * // config/filesystems.php
 * 'voltn' => [
 *     'driver' => 'voltn',
 *     'base_uri' => env('VOLTN_BASE_URI'),
 *     'client_id' => env('VOLTN_CLIENT_ID'),
 *     'client_secret' => env('VOLTN_CLIENT_SECRET'),
 *     'root' => env('VOLTN_ROOT_FOLDER_ID'),
 *     'trash' => false,
 * ],
 * ```
 *
 * Optional keys: `chunked_upload_threshold` and `chunk_size` (bytes),
 * `timeout` (seconds, default 300: it covers the whole transfer, uploads included) and `connect_timeout` (seconds, default 5).
 * The access token is cached (encrypted) in the default cache store through
 * {@see CacheTokenStorage}.
 */
final class VoltnServiceProvider extends ServiceProvider
{
    public const DRIVER = 'voltn';

    private const DEFAULT_TIMEOUT = 300.0;

    private const DEFAULT_CONNECT_TIMEOUT = 5.0;

    public function boot(): void
    {
        // The manager rebinds this closure's scope: refer to this class by name.
        Storage::extend(self::DRIVER, static function (Container $app, array $config): FilesystemAdapter {
            /** @var array<string, mixed> $config */
            $adapter = VoltnServiceProvider::createAdapter($app, $config);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }

    /**
     * Build a {@see VoltnAdapter} from a `voltn` disk configuration array.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when a required option is missing or invalid
     */
    public static function createAdapter(Container $app, array $config): VoltnAdapter
    {
        $baseUri = self::requireString($config, 'base_uri');
        $clientId = self::requireString($config, 'client_id');
        $clientSecret = self::requireString($config, 'client_secret');
        $root = self::requireFolderId($config);

        $cache = $app->make('cache.store');
        $encrypter = $app->make('encrypter');

        if (!$cache instanceof Repository || !$encrypter instanceof StringEncrypter) {
            throw new InvalidArgumentException('The "voltn" disk requires the Laravel cache and encrypter services.');
        }

        $builder = ClientBuilder::create($baseUri)
            ->withClientCredentials($clientId, $clientSecret)
            ->withTokenStorage(new CacheTokenStorage($cache, $encrypter, CacheTokenStorage::keyFor($baseUri, $clientId)));

        if (class_exists(GuzzleClient::class)) {
            $builder->withHttpClient(new GuzzleClient([
                'timeout' => self::number($config, 'timeout', self::DEFAULT_TIMEOUT),
                'connect_timeout' => self::number($config, 'connect_timeout', self::DEFAULT_CONNECT_TIMEOUT),
            ]));
        }

        return new VoltnAdapter(
            $builder->build(),
            $root,
            self::boolean($config, 'trash', true),
            self::integer($config, 'chunked_upload_threshold', 52428800),
            self::integer($config, 'chunk_size', 8388608),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'The "voltn" disk requires a non-empty "%s" option (check your config/filesystems.php and .env).',
                $key,
            ));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requireFolderId(array $config): int|string
    {
        $value = $config['root'] ?? null;

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $value = trim($value);

            return ctype_digit($value) ? (int) $value : $value;
        }

        throw new InvalidArgumentException(
            'The "voltn" disk requires a "root" option: the id of the Voltn folder to use as the disk root.',
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function boolean(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($bool === null) {
            throw new InvalidArgumentException(sprintf('The "voltn" disk "%s" option must be a boolean.', $key));
        }

        return $bool;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function integer(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf('The "voltn" disk "%s" option must be a positive integer.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function number(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException(sprintf('The "voltn" disk "%s" option must be a number of seconds.', $key));
        }

        return (float) $value;
    }
}
