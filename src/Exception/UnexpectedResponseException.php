<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

/**
 * Raised when a response has an HTTP status code the SDK does not have a
 * more specific mapping for (e.g. an unusual 2xx/3xx), or when a 2xx
 * response body was expected to be JSON but could not be decoded as such.
 */
class UnexpectedResponseException extends VoltnException
{
}
