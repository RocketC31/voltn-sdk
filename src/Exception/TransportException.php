<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Wraps a PSR-18 {@see ClientExceptionInterface} raised by the underlying
 * HTTP client itself (connection failures, timeouts, DNS errors, ...) where
 * no HTTP response was ever received.
 */
class TransportException extends VoltnException
{
    public function __construct(
        string $message,
        ?RequestInterface $request,
        ClientExceptionInterface $previous,
    ) {
        parent::__construct(
            message: $message,
            statusCode: null,
            request: $request,
            response: null,
            rawBody: null,
            previous: $previous,
        );
    }
}
