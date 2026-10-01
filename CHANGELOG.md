# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Flysystem v3 adapter `Flysystem\VoltnAdapter` (optional, requires
  `league/flysystem` ^3.0): paths relative to a root folder resolved through
  `SyncClient`, automatic creation of missing parent folders, streamed
  reads/writes, TUS uploads above a configurable size threshold, trash or
  permanent deletes, MIME types derived from the file extension.
- Laravel integration (optional, Laravel 11 to 13): `Laravel\VoltnServiceProvider`,
  auto-discovered, registers a `voltn` filesystem driver configured from the
  disk array (`base_uri`, `client_id`, `client_secret`, `root`, `trash`,
  `chunked_upload_threshold`, `chunk_size`, `timeout`, `connect_timeout`),
  e.g. as a `spatie/laravel-backup` destination.
- `Laravel\CacheTokenStorage`: a `TokenStorage` keeping the access token,
  encrypted, in a Laravel cache repository until shortly before it expires.

## [0.2.0] - 2026-10-01

### Added

- `SyncClient` (`$client->sync()`): `folderAt()` / `fileAt()` (`GET /path`),
  `objectPath()` (`GET /objectpath`), `isChildOf()` (`GET /ischildof`) and
  `quotas()` (`GET /quotas`), with the `ObjectType`, `Quotas` and
  `QuotaUsage` models.
- Permanent deletion: `FileClient::delete(..., trash: false)` and
  `FolderClient::delete(..., trash: false)`; `FileVersionScope` to choose
  which versions of a file are deleted.
- `File::getGuid()`, shared by all versions of a file.

## [0.1.0] - 2026-10-01

### Added

- Initial release of the Voltn (formerly NetExplorer) PHP SDK,
  published as `rocketc31/voltn-sdk` under the `RocketC31\Voltn` namespace.
- `Client` / `ClientBuilder` with PSR-18/PSR-17 auto-discovery via `php-http/discovery`.
- OAuth2 support (`Auth\OAuth2Client`): authorization code (with optional PKCE),
  refresh token, and client credentials grants.
- `AuthenticationMiddleware` with transparent token refresh and retry-once-on-401.
- `TokenStorage` interface with an `InMemoryTokenStorage` implementation.
- Resource clients: `FolderClient`, `FileClient`, `RootClient`, `TokenClient`.
- `TokenClient::previewUrl()` and `TokenClient::downloadUrl()`, which build
  browser-safe URLs from single-purpose file tokens (`FileTokenType`:
  `Preview`, `Edit`, `Download`).
- Streaming upload/download support, including a minimal TUS 1.0
  (creation + core extensions) client for large-file uploads via
  `Upload\TusUploadManager`.
- Defensive exception hierarchy (`Exception\*`) mapping HTTP status codes to
  typed exceptions without assuming a fixed error response body shape.

[Unreleased]: https://github.com/RocketC31/voltn-sdk/compare/0.2.0...HEAD
[0.2.0]: https://github.com/RocketC31/voltn-sdk/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/RocketC31/voltn-sdk/releases/tag/0.1.0
