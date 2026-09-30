<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Upload;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\ExceptionFactory;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\File;

/**
 * Large-file upload manager: bootstraps a session via Voltn's
 * `POST /file/tus`, then speaks the standard TUS 1.0 protocol (creation +
 * core extensions only — no concatenation/expiration) against `/api/tus`
 * to transfer the content in chunks, and finally closes the session via
 * Voltn's documented closing call.
 *
 * This is a minimal, dependency-free TUS *client* implementation; it does
 * not attempt to be a general-purpose TUS library.
 *
 * @see https://tus.io/protocols/resumable-upload
 */
final class TusUploadManager
{
    private const TUS_VERSION = '1.0.0';

    public function __construct(
        private readonly HttpTransport $moduleTransport,
        private readonly HttpTransport $tusTransport,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Upload `$content` (exactly `$size` bytes) as `$filename` into
     * `$target` (destination folder id), chunked per
     * `$options->chunkSize`, reporting progress via
     * `$options->onProgress` if set.
     */
    public function upload(
        int|string $target,
        string $filename,
        StreamInterface $content,
        int $size,
        ?UploadOptions $options = null,
    ): File {
        $options ??= new UploadOptions();

        $sessionToken = $this->bootstrapSession($target, $filename, $size);
        $uploadUrl = $this->createUpload($sessionToken, $filename, $size);

        $this->uploadChunks($uploadUrl, $content, $size, $options);

        return $this->finalize($sessionToken);
    }

    /**
     * Resume a previously-started upload: queries the current offset via a
     * TUS `HEAD` request, seeks `$content` to that offset (the stream must
     * be seekable), and continues sending chunks from there.
     */
    public function resume(
        string $sessionToken,
        string $uploadUrl,
        StreamInterface $content,
        int $totalSize,
        ?UploadOptions $options = null,
    ): File {
        $options ??= new UploadOptions();

        $offset = $this->getUploadOffset($uploadUrl);

        if ($offset > 0) {
            if (!$content->isSeekable()) {
                throw new UnexpectedResponseException(sprintf(
                    'Cannot resume upload at offset %d: the provided content stream is not seekable.',
                    $offset,
                ));
            }

            $content->seek($offset);
        }

        $this->uploadChunks($uploadUrl, $content, $totalSize, $options, $offset);

        return $this->finalize($sessionToken);
    }

    /**
     * Query the current offset of an in-progress TUS upload (core `HEAD`
     * extension), e.g. to decide whether/where to resume.
     */
    public function getUploadOffset(string $uploadUrl): int
    {
        $request = $this->requestFactory
            ->createRequest('HEAD', $this->resolveUri($uploadUrl))
            ->withHeader('Tus-Resumable', self::TUS_VERSION);

        $response = $this->tusTransport->dispatch($request);

        return $this->requireOffsetHeader($response, 'Upload not found or offset unavailable.');
    }

    private function bootstrapSession(int|string $target, string $filename, int $size): string
    {
        $response = $this->moduleTransport->sendForJson('POST', '/file/tus', null, [
            'name' => $filename,
            'target' => $target,
            'size' => $size,
        ]);

        $sessionToken = $this->extractSessionToken($response);

        if ($sessionToken === null) {
            throw new UnexpectedResponseException(
                'The /file/tus bootstrap endpoint did not return a recognizable session token.',
            );
        }

        return $sessionToken;
    }

    /**
     * @param array<string, mixed>|null $response
     */
    private function extractSessionToken(?array $response): ?string
    {
        if ($response === null) {
            return null;
        }

        foreach (['token', 'sessionKey', 'session_key', 'session_token', 'key'] as $key) {
            $value = $response[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function createUpload(string $sessionToken, string $filename, int $size): string
    {
        $metadata = $this->buildUploadMetadata([
            'filename' => $filename,
            'token' => $sessionToken,
        ]);

        $request = $this->tusTransport
            ->createRequest('POST', '')
            ->withHeader('Tus-Resumable', self::TUS_VERSION)
            ->withHeader('Upload-Length', (string) $size)
            ->withHeader('Upload-Metadata', $metadata)
            ->withHeader('Content-Length', '0');

        $response = $this->tusTransport->dispatch($request);

        if ($response->getStatusCode() !== 201) {
            throw ExceptionFactory::fromResponse($response, $request);
        }

        $location = $response->getHeaderLine('Location');

        if ($location === '') {
            throw new UnexpectedResponseException('The TUS creation response did not include a Location header.');
        }

        return $this->resolveUri($location);
    }

    private function uploadChunks(
        string $uploadUrl,
        StreamInterface $content,
        int $totalSize,
        UploadOptions $options,
        int $offset = 0,
    ): void {
        while ($offset < $totalSize && !$content->eof()) {
            $chunk = $content->read(min($options->chunkSize, $totalSize - $offset));

            if ($chunk === '') {
                break;
            }

            $request = $this->requestFactory
                ->createRequest('PATCH', $this->resolveUri($uploadUrl))
                ->withHeader('Tus-Resumable', self::TUS_VERSION)
                ->withHeader('Upload-Offset', (string) $offset)
                ->withHeader('Content-Type', 'application/offset+octet-stream')
                ->withBody($this->streamFactory->createStream($chunk));

            $response = $this->tusTransport->dispatch($request);

            if ($response->getStatusCode() !== 204) {
                throw ExceptionFactory::fromResponse($response, $request);
            }

            $newOffset = $this->optionalOffsetHeader($response);
            $offset = $newOffset ?? ($offset + strlen($chunk));

            if ($options->onProgress !== null) {
                ($options->onProgress)($offset, $totalSize);
            }
        }
    }

    private function finalize(string $sessionToken): File
    {
        $data = $this->moduleTransport->sendForJson('POST', '/file/tus', null, [
            'token' => $sessionToken,
        ]);

        return File::fromArray($data ?? []);
    }

    /**
     * @param array<string, string> $pairs
     */
    private function buildUploadMetadata(array $pairs): string
    {
        $parts = [];

        foreach ($pairs as $key => $value) {
            $parts[] = sprintf('%s %s', $key, base64_encode($value));
        }

        return implode(',', $parts);
    }

    private function requireOffsetHeader(ResponseInterface $response, string $errorMessageIfMissing): int
    {
        $offset = $this->optionalOffsetHeader($response);

        if ($offset === null) {
            throw new UnexpectedResponseException($errorMessageIfMissing);
        }

        return $offset;
    }

    private function optionalOffsetHeader(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Upload-Offset');

        if ($header === '' || !is_numeric($header)) {
            return null;
        }

        return (int) $header;
    }

    private function resolveUri(string $maybeRelativeUri): string
    {
        if (str_starts_with($maybeRelativeUri, 'http://') || str_starts_with($maybeRelativeUri, 'https://')) {
            return $maybeRelativeUri;
        }

        return rtrim($this->tusTransport->getBaseUri(), '/') . '/' . ltrim($maybeRelativeUri, '/');
    }
}
