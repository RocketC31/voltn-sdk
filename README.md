# Voltn SDK for PHP

[![CI](https://github.com/RocketC31/voltn-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/RocketC31/voltn-sdk/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A framework-agnostic PHP SDK for the [Voltn](https://www.voltn.eu/) file-storage /
EDM API (formerly **NetExplorer**): folders, files (including streamed
uploads/downloads and large-file TUS transfers), root spaces, file tokens,
and the full OAuth2 flow. API reference: <https://api.voltn.eu/v4/>.

> **Unofficial.** This is a community project. It is not affiliated with,
> endorsed by, or supported by Voltn. "Voltn" and "NetExplorer" are
> trademarks of their respective owner.

On top of the framework-agnostic SDK, the package ships an optional
[Flysystem v3](https://flysystem.thephpleague.com/) adapter and a Laravel
`voltn` disk driver, so that `spatie/laravel-backup` (and anything else built
on Flysystem) can target Voltn the same way it targets S3 or Dropbox. Both
are opt-in: the core SDK does not depend on Flysystem or Laravel.

## Requirements

- PHP 8.2+
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client and
  [PSR-17](https://www.php-fig.org/psr/psr-17/) factories. If your
  application doesn't already have one (e.g. via Symfony HttpClient or
  Guzzle), install Guzzle:

  ```bash
  composer require guzzlehttp/guzzle
  ```

  `php-http/discovery` will find it automatically — you don't need to wire
  anything up yourself.

## Installation

```bash
composer require rocketc31/voltn-sdk
```

Until the package is published on Packagist, install it as a
[VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs):

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/RocketC31/voltn-sdk" }
    ]
}
```

## Quickstart

### Fire-and-forget: `client_credentials`

This is the mode a backend integration with no end user in the loop (e.g. a
scheduled backup job) will typically use. No token storage is required —
the SDK obtains and refreshes tokens on its own for as long as the process
lives.

```php
use RocketC31\Voltn\ClientBuilder;

$client = ClientBuilder::create('https://tenant.voltn.example')
    ->withClientCredentials('client-id', 'client-secret')
    ->build();

$folder = $client->folders()->get(1, depth: 1);

foreach ($folder->getFiles() as $file) {
    echo $file->getName(), ' (', $file->size(), ' bytes)', PHP_EOL;
}
```

### Full interactive flow (authorization code, optionally with PKCE)

```php
use RocketC31\Voltn\Auth\Pkce;
use RocketC31\Voltn\ClientBuilder;

// Step 1: build the OAuth2 client to get an authorization URL.
$client = ClientBuilder::create('https://tenant.voltn.example')
    ->withOAuth2('client-id', 'client-secret') // secret omitted entirely for a public client using PKCE
    ->build();

$pkce = Pkce::generate(); // optional, for public clients
$authorizationUrl = $client->oauth2()->getAuthorizationUrl(
    redirectUri: 'https://app.example.com/callback',
    state: bin2hex(random_bytes(16)),
    pkce: $pkce,
);

// Redirect the user to $authorizationUrl. Persist $pkce->codeVerifier
// (e.g. in session) until the callback comes back.

// Step 2: in your callback handler, exchange the code for a token.
$token = $client->oauth2()->exchangeAuthorizationCode(
    code: $_GET['code'],
    redirectUri: 'https://app.example.com/callback',
    codeVerifier: $pkce->codeVerifier, // or clientSecret: '...' for a confidential client
);

// Step 3: persist $token (e.g. in session) and build the "real" client
// with a TokenStorage so subsequent requests auto-refresh it.
use RocketC31\Voltn\Auth\TokenStorage;

$storage = /* your TokenStorage implementation, e.g. session-backed */;
$storage->set($token);

$client = ClientBuilder::create('https://tenant.voltn.example')
    ->withOAuth2('client-id', 'client-secret')
    ->withTokenStorage($storage)
    ->build();
```

### Fully manual

You manage the `AccessToken` yourself (obtained however you like) and hand
it to the SDK. No refresh or retry-on-401 logic is engaged in this mode —
a 401 propagates as an `AuthenticationException`.

```php
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\ClientBuilder;

$token = AccessToken::fromArray($someTokenArrayYouLoadedYourself);

$client = ClientBuilder::create('https://tenant.voltn.example')
    ->withAccessToken($token)
    ->build();
```

## Streaming uploads and downloads

Uploads and downloads work over PSR-7 `StreamInterface` and never buffer a
full file's content into memory.

```php
// Download.
$stream = $client->files()->download($fileId);
$destination = fopen('/path/to/local/file', 'wb');
while (!$stream->eof()) {
    fwrite($destination, $stream->read(8192));
}
fclose($destination);

// Simple upload (creates a new version automatically if the name already
// exists in the target folder).
use GuzzleHttp\Psr7\Utils;

$content = Utils::streamFor(fopen('/path/to/local/file.pdf', 'rb'));
$file = $client->files()->upload($folderId, 'file.pdf', $content, 'application/pdf');
```

### Large files: TUS chunked upload

For large files, prefer `$client->tus()`, which bootstraps a session via
Voltn's `POST /file/tus` and then speaks the standard
[TUS 1.0](https://tus.io/protocols/resumable-upload) protocol (creation +
core extensions) to transfer the content in configurable chunks, with an
optional progress callback:

```php
use RocketC31\Voltn\Upload\UploadOptions;

$content = Utils::streamFor(fopen('/path/to/huge-file.zip', 'rb'));

$file = $client->tus()->upload(
    target: $folderId,
    filename: 'huge-file.zip',
    content: $content,
    size: filesize('/path/to/huge-file.zip'),
    options: new UploadOptions(
        chunkSize: 8 * 1024 * 1024,
        onProgress: fn (int $sent, int $total) => printf("%d / %d bytes\n", $sent, $total),
    ),
);
```

## Previewing and downloading from a browser

Your OAuth2 access token must never reach a browser. Instead, the SDK
asks Voltn for a single-purpose file token and returns a URL built
around it, which you can hand to the front end:

```php
// Iframe `src` or new tab: Voltn renders PDFs, images and audio/video,
// and redirects office documents to the configured editing platform.
$previewUrl = $client->tokens()->previewUrl($fileId);

// Direct download from Voltn: the content doesn't transit through your server.
$downloadUrl = $client->tokens()->downloadUrl($fileId);
```

Each call generates a fresh token, so build these URLs on demand (e.g.
in the controller that serves the page) rather than storing them. If you
need the raw token, `createFileToken()` accepts a `FileTokenType`
(`Preview`, `Edit` or `Download`):

```php
use RocketC31\Voltn\Model\FileTokenType;

$token = $client->tokens()->createFileToken($fileId, FileTokenType::Edit);
$token->getToken();
```

## Path-based lookups and quotas

`$client->sync()` wraps Voltn's synchronisation endpoints, which let you
address items by path instead of walking folders id by id:

```php
use RocketC31\Voltn\Model\ObjectType;

// One call whatever the depth (a trailing "/" is added for folders).
$folder = $client->sync()->folderAt($rootFolderId, 'backups/my-site');
$file = $client->sync()->fileAt($rootFolderId, 'backups/my-site/2026-10-01.zip');

// The other way round: id -> path relative to the root.
$path = $client->sync()->objectPath($rootFolderId, ObjectType::File, $fileId);

// Is a folder below a root? (true / false; 403 = below it but not accessible)
$client->sync()->isChildOf($folderId, $rootFolderId);

// Platform, user and folder quotas (bytes; null quota = unlimited).
$quotas = $client->sync()->quotas($folderId);
$quotas->getFolder()?->getRemaining();
```

## Deleting

Deleted items go to the trash by default (recoverable). Pass
`trash: false` to delete permanently, e.g. when rotating backups so the
trash doesn't fill up the quota:

```php
use RocketC31\Voltn\Model\FileVersionScope;

$client->files()->delete($fileId);                  // trash, all versions
$client->files()->delete($fileId, trash: false);    // permanent
$client->files()->delete($fileId, versions: FileVersionScope::None); // current version only
$client->folders()->delete($folderId, trash: false);
```

Every version of a file shares the same `getGuid()`, whereas `getId()`
designates one version.

## Flysystem adapter

Requires `league/flysystem` ^3.0:

```bash
composer require league/flysystem
```

```php
use League\Flysystem\Filesystem;
use RocketC31\Voltn\Flysystem\VoltnAdapter;

$adapter = new VoltnAdapter(
    $client,                 // a RocketC31\Voltn\Client
    $rootFolderId,           // every Flysystem path is relative to this folder
    trash: true,             // false = permanent deletes
    chunkedUploadThreshold: 50 * 1024 * 1024, // bigger contents go through TUS
    chunkSize: 8 * 1024 * 1024,
);

$filesystem = new Filesystem($adapter);
$filesystem->writeStream('backups/my-site/2026-10-01.zip', fopen('/tmp/backup.zip', 'rb'));
```

Behaviour worth knowing:

- Paths are resolved in a single call each through `$client->sync()`;
  missing parent folders are created on write. `..` cannot escape the root.
- Writing to an existing path creates a **new Voltn version** of the file
  (the platform's native behaviour) rather than replacing it.
- Streams whose size is known and larger than `chunkedUploadThreshold` are
  uploaded through TUS; everything else in a single streamed multipart
  upload. Nothing is buffered fully into memory.
- **Visibility is not supported**: `setVisibility()` / `visibility()` throw.
- **MIME types are derived from the file extension** (Voltn does not expose
  one); the file must still exist.
- Deleting a missing file or directory is a no-op; deleting the root is
  refused. `move()` is a copy followed by a delete of the source.

## Laravel

The `voltn` disk driver is registered automatically (package
auto-discovery) when `illuminate/support` and `illuminate/filesystem`
(Laravel 11, 12 or 13) and `league/flysystem` are installed.

```php
// config/filesystems.php
'disks' => [
    'voltn' => [
        'driver' => 'voltn',
        'base_uri' => env('VOLTN_BASE_URI'),           // https://tenant.voltn.example
        'client_id' => env('VOLTN_CLIENT_ID'),
        'client_secret' => env('VOLTN_CLIENT_SECRET'),
        'root' => env('VOLTN_ROOT_FOLDER_ID'),         // id of the folder used as the disk root
        'trash' => false, // permanent deletes, e.g. for backup rotation
        // Optional:
        // 'chunked_upload_threshold' => 52428800,     // bytes, TUS above this
        // 'chunk_size' => 8388608,                    // bytes per TUS chunk
        // 'timeout' => 300,                           // seconds per HTTP request, transfer included (0 = none)
        // 'connect_timeout' => 5,                     // seconds
    ],
],
```

```php
Storage::disk('voltn')->put('reports/2026-10.csv', $csv);
```

`base_uri`, `client_id`, `client_secret` and `root` are required (an
`InvalidArgumentException` is thrown otherwise). The disk authenticates with
`client_credentials`; the access token is cached, encrypted with the
application key, in the default cache store (`CacheTokenStorage`), so that
successive requests and jobs don't fetch a new token every time. When
`guzzlehttp/guzzle` is installed it is used with the configured timeouts;
otherwise the PSR-18 client is auto-discovered. `timeout` bounds each HTTP
request as a whole: keep it large enough for one chunk (or one upload below
`chunked_upload_threshold`) and for downloads on your bandwidth, or set it to
`0` to disable it.

### With spatie/laravel-backup

Add the disk to the backup destinations:

```php
// config/backup.php
'destination' => [
    'disks' => ['voltn'],
],
```

Use `'trash' => false` on the disk: old backups removed by the cleanup task
are then deleted permanently instead of piling up in the Voltn trash, which
would otherwise keep eating the quota.

## Impersonation

If your application acts on behalf of other Voltn users, set a
default `As:` header:

```php
$client = ClientBuilder::create('https://tenant.voltn.example')
    ->withClientCredentials('client-id', 'client-secret')
    ->withImpersonation($userId)
    ->build();
```

## Exception handling

Every error response is mapped to a typed exception, all extending
`RocketC31\Voltn\Exception\VoltnException`:

| HTTP status | Exception |
| --- | --- |
| n/a (no response at all — connection/timeout) | `TransportException` |
| 401 | `AuthenticationException` |
| 403 | `AuthorizationException` |
| 404 | `NotFoundException` |
| 5xx | `ServerException` |
| anything else unexpected, or an undecodable 2xx JSON body | `UnexpectedResponseException` |

Voltn does not guarantee a consistent error response body shape (a
500 in particular "may or may not include a body"), so every accessor is
defensive:

```php
use RocketC31\Voltn\Exception\VoltnException;

try {
    $client->files()->get($fileId);
} catch (VoltnException $e) {
    $e->getStatusCode();  // ?int
    $e->getJson();        // ?array — decoded body, if any and if it was a JSON object/array
    $e->getApiMessage();  // ?string — best-effort human-readable message
    $e->getRawBody();     // ?string — raw response body, if any
}
```

`FolderClient::exists()` and `FileClient::exists()` catch `NotFoundException`
for you and return a plain `bool`.

## A note on the API base path

Voltn's per-tenant API is served at `https://{platform}/api`
(no version segment despite what the documentation site's own URL might
suggest); "module" in Voltn's own docs is just a placeholder word
for "whatever resource path applies" (`folder`, `file`, `roots`, ...), not
a configurable segment. This SDK asks only for the tenant host (e.g.
`https://tenant.voltn.example`) and appends `/api` itself for all
regular resource calls (folders, files, roots, tokens).

OAuth2 endpoints (`/oauth2/authorize`, `/oauth2/token`) are the documented
exception: they sit at the tenant root, not under `/api`, and the SDK
talks to them there directly.

TUS chunked transfers are the other documented exception: they run against
`https://{platform}/api/tus` directly, which the SDK also handles for you.

## Development

```bash
composer install
vendor/bin/phpunit                                  # unit tests (integration tests are skipped unless env vars are set)
vendor/bin/phpstan analyse                           # static analysis (level 8)
vendor/bin/php-cs-fixer fix --dry-run --diff         # code style check
```

Integration tests are opt-in and run against a real Voltn tenant:

```bash
VOLTN_TEST_BASE_URI=https://tenant.voltn.example \
VOLTN_TEST_CLIENT_ID=... \
VOLTN_TEST_CLIENT_SECRET=... \
vendor/bin/phpunit --testsuite=integration
```

## Scope

Deliberately out of scope for this SDK (v1):

- Trash / recycle bin endpoints.
- OAuth2 app-management endpoints (registering apps, resetting secrets,
  listing/revoking tokens).
- Rate-limit handling — Voltn does not document any rate limiting.
- A `mimeType()` accessor on `File` — Voltn only exposes a coarse
  `file_type` (`image` / `document` / `video`) for preview purposes, not a
  real MIME type. The Flysystem adapter derives MIME types from the file
  extension instead.

## License

MIT. See [LICENSE.md](LICENSE.md).
