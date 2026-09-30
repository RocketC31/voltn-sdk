<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Exception;

/**
 * Raised for HTTP 5xx responses. Voltn's documentation explicitly
 * notes that a 500 response "may or may not include a body" with further
 * detail, so consumers should not assume {@see self::getJson()} or
 * {@see self::getApiMessage()} return anything.
 */
class ServerException extends VoltnException
{
}
