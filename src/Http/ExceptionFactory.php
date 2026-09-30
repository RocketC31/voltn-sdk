<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RocketC31\Voltn\Exception\AuthenticationException;
use RocketC31\Voltn\Exception\AuthorizationException;
use RocketC31\Voltn\Exception\NotFoundException;
use RocketC31\Voltn\Exception\ServerException;
use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Exception\VoltnException;

/**
 * Maps an HTTP response with a non-2xx status code to the appropriate
 * {@see VoltnException} subclass.
 *
 * Voltn error bodies are not guaranteed to have a consistent JSON
 * shape (the docs explicitly warn that a 500 "may or may not include a
 * body"), so this only ever reads the raw body defensively; parsing it
 * further is left to {@see VoltnException::getJson()}.
 */
final class ExceptionFactory
{
    private function __construct()
    {
    }

    public static function fromResponse(ResponseInterface $response, ?RequestInterface $request = null): VoltnException
    {
        $statusCode = $response->getStatusCode();

        $body = (string) $response->getBody();
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        }

        $message = sprintf('Voltn API request failed with status %d.', $statusCode);

        return match (true) {
            401 === $statusCode => new AuthenticationException($message, $statusCode, $request, $response, $body),
            403 === $statusCode => new AuthorizationException($message, $statusCode, $request, $response, $body),
            404 === $statusCode => new NotFoundException($message, $statusCode, $request, $response, $body),
            $statusCode >= 500 => new ServerException($message, $statusCode, $request, $response, $body),
            default => new UnexpectedResponseException($message, $statusCode, $request, $response, $body),
        };
    }
}
