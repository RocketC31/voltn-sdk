<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RocketC31\Voltn\Exception\TransportException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use Throwable;

/**
 * Thin HTTP layer sitting directly on top of PSR-18/PSR-17: builds requests
 * against a fixed base URI, dispatches them through the configured PSR-18
 * client, and translates the result into either decoded JSON, a raw
 * response body stream, or a mapped {@see \RocketC31\Voltn\Exception\VoltnException}.
 *
 * This class has no awareness of authentication: the `$httpClient` it is
 * given is expected to already be an {@see \RocketC31\Voltn\Auth\AuthenticationMiddleware}
 * decorator (or a plain client, for the unauthenticated OAuth2 endpoints).
 */
final class HttpTransport
{
    private const JSON_CONTENT_TYPE = 'application/json';

    /**
     * @param array<string, string> $defaultHeaders
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUri,
        private readonly array $defaultHeaders = [],
    ) {
    }

    public function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    /**
     * Build a request against this transport's base URI, with default
     * headers applied. Exposed for callers (e.g. the TUS upload manager)
     * that need full control over method/headers/body.
     *
     * @param array<string, scalar>|null $query
     */
    public function createRequest(string $method, string $path, ?array $query = null): RequestInterface
    {
        $uri = $this->buildUri($path, $query);
        $request = $this->requestFactory->createRequest($method, $uri);

        foreach ($this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Send a fully-built request and return the raw response, regardless of
     * its status code. Transport-level failures (no response received at
     * all) are converted into a {@see TransportException}.
     */
    public function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException(
                sprintf('Failed to send request to %s: %s', (string) $request->getUri(), $exception->getMessage()),
                $request,
                $exception,
            );
        }
    }

    /**
     * Send an already fully-built request (e.g. one started via
     * {@see self::createRequest()} and customized further, such as
     * attaching a raw binary body) and decode its JSON response, applying
     * the same status-code and body handling as {@see self::sendForJson()}.
     *
     * @return array<string, mixed>|null
     */
    public function sendPreparedRequestForJson(RequestInterface $request): ?array
    {
        $response = $this->dispatch($request);

        return $this->decodeJsonResponse($response, $request);
    }

    /**
     * @param array<string, scalar>|null $query
     * @param array<string, mixed>|null  $jsonBody
     * @param array<string, string>      $headers
     *
     * @return array<string, mixed>|null decoded JSON body, or null for an empty (e.g. 204) body
     */
    public function sendForJson(
        string $method,
        string $path,
        ?array $query = null,
        ?array $jsonBody = null,
        array $headers = [],
    ): ?array {
        $request = $this->createRequest($method, $path, $query);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($jsonBody !== null) {
            $request = $request
                ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
                ->withBody($this->streamFactory->createStream(json_encode($jsonBody, JSON_THROW_ON_ERROR)));
        }

        if (!$request->hasHeader('Accept')) {
            $request = $request->withHeader('Accept', self::JSON_CONTENT_TYPE);
        }

        $response = $this->dispatch($request);

        return $this->decodeJsonResponse($response, $request);
    }

    /**
     * Send a request and return the response body as a lazily-readable
     * stream, e.g. for file downloads. The stream is never buffered fully
     * into memory by this method; it is the caller's responsibility to
     * consume it (or copy it into a destination) as a stream.
     *
     * @param array<string, scalar>|null $query
     * @param array<string, string>      $headers
     */
    public function sendForStream(
        string $method,
        string $path,
        ?array $query = null,
        array $headers = [],
    ): StreamInterface {
        $request = $this->createRequest($method, $path, $query);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->dispatch($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw ExceptionFactory::fromResponse($response, $request);
        }

        return $response->getBody();
    }

    /**
     * Send a `multipart/form-data` request built from scalar `$fields` and
     * `$fileParts` (each a {@see MultipartFilePart}), without buffering any
     * file content into memory.
     *
     * @param array<string, scalar>       $fields
     * @param list<MultipartFilePart>     $fileParts
     * @param array<string, scalar>|null  $query
     * @param array<string, string>       $headers
     *
     * @return array<string, mixed>|null
     */
    public function sendMultipart(
        string $method,
        string $path,
        array $fields,
        array $fileParts,
        ?array $query = null,
        array $headers = [],
    ): ?array {
        $boundary = '----VoltnSdk' . bin2hex(random_bytes(16));

        $request = $this->createRequest($method, $path, $query);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request
            ->withHeader('Content-Type', sprintf('multipart/form-data; boundary=%s', $boundary))
            ->withBody($this->buildMultipartBody($boundary, $fields, $fileParts));

        if (!$request->hasHeader('Accept')) {
            $request = $request->withHeader('Accept', self::JSON_CONTENT_TYPE);
        }

        $response = $this->dispatch($request);

        return $this->decodeJsonResponse($response, $request);
    }

    /**
     * @param array<string, mixed>|null $query
     */
    private function buildUri(string $path, ?array $query): string
    {
        $trimmedPath = ltrim($path, '/');
        $uri = $trimmedPath === '' ? rtrim($this->baseUri, '/') : rtrim($this->baseUri, '/') . '/' . $trimmedPath;

        if ($query !== null && $query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    /**
     * @param array<string, scalar>   $fields
     * @param list<MultipartFilePart> $fileParts
     */
    private function buildMultipartBody(string $boundary, array $fields, array $fileParts): StreamInterface
    {
        $streams = [];

        foreach ($fields as $name => $value) {
            $streams[] = $this->streamFactory->createStream(
                sprintf(
                    "--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n",
                    $boundary,
                    $name,
                    (string) $value,
                ),
            );
        }

        foreach ($fileParts as $filePart) {
            $streams[] = $this->streamFactory->createStream(sprintf(
                "--%s\r\nContent-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\nContent-Type: %s\r\n\r\n",
                $boundary,
                $filePart->fieldName,
                $filePart->filename,
                $filePart->contentType,
            ));
            $streams[] = $filePart->content;
            $streams[] = $this->streamFactory->createStream("\r\n");
        }

        $streams[] = $this->streamFactory->createStream(sprintf("--%s--\r\n", $boundary));

        return new MultipartStream($streams);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonResponse(ResponseInterface $response, RequestInterface $request): ?array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw ExceptionFactory::fromResponse($response, $request);
        }

        $body = (string) $response->getBody();

        if (trim($body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new UnexpectedResponseException(
                'Expected a JSON response body but the body could not be decoded.',
                $statusCode,
                $request,
                $response,
                $body,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new UnexpectedResponseException(
                'Expected a JSON object/array response body.',
                $statusCode,
                $request,
                $response,
                $body,
            );
        }

        return $decoded;
    }
}
