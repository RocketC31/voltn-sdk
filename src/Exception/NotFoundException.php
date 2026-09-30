<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

/**
 * Raised for HTTP 404 responses: the requested file, folder, or other
 * resource does not exist (or is not visible to the authenticated
 * principal).
 */
class NotFoundException extends VoltnException
{
}
