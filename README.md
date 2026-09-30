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

This SDK is intended as the foundation for a `League\Flysystem` adapter,
so that `spatie/laravel-backup` (and anything else built on Flysystem) can
target Voltn the same way it targets S3 or Dropbox. This repository
contains **only** the SDK: no Flysystem or Laravel code.

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
for you and return a plain `bool` — handy for a future Flysystem adapter's
`fileExists()`/`directoryExists()`.

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
  real MIME type. A future Flysystem adapter built on top of this SDK will
  need to derive MIME type from the file extension itself.
- The Flysystem adapter and Laravel integration themselves — to be built
  in a separate repository on top of this SDK.

## License

MIT. See [LICENSE.md](LICENSE.md).
