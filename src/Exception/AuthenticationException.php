<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

/**
 * Raised for HTTP 401 responses: the access token is missing, invalid, or
 * expired, and could not be transparently refreshed.
 */
class AuthenticationException extends VoltnException
{
}
