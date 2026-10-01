# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
