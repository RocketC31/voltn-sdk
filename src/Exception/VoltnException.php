<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Base exception for all errors raised by the SDK once a request has been
 * dispatched (i.e. an HTTP response, or lack thereof, is available).
 *
 * Voltn error response bodies are not guaranteed to have a consistent
 * JSON shape: some error responses (particularly 5xx) may have no body at
 * all. Every accessor here is therefore defensive and nullable rather than
 * assuming a fixed `{error, message}` schema.
 */
class VoltnException extends RuntimeException
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $json;

    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly ?RequestInterface $request = null,
        private readonly ?ResponseInterface $response = null,
        private readonly ?string $rawBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);

        $this->json = $this->decodeJson($rawBody);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getRequest(): ?RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * The response body decoded as JSON, if it was present and valid.
     *
     * @return array<string, mixed>|null
     */
    public function getJson(): ?array
    {
        return $this->json;
    }

    /**
     * Best-effort extraction of a human-readable message from the response
     * body. Voltn does not guarantee a stable error schema, so this
     * probes a handful of plausible keys and falls back to null rather than
     * throwing or guessing.
     */
    public function getApiMessage(): ?string
    {
        $json = $this->json;

        if ($json === null) {
            return null;
        }

        foreach (['message', 'error_description', 'error', 'detail', 'title'] as $key) {
            $value = $json[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(?string $rawBody): ?array
    {
        if ($rawBody === null || trim($rawBody) === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
