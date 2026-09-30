<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

/**
 * Raised for HTTP 403 responses: the authenticated principal does not have
 * permission to perform the requested operation.
 */
class AuthorizationException extends VoltnException
{
}
