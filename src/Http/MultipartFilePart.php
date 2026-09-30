<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Http;

use Psr\Http\Message\StreamInterface;

/**
 * A single file part of a `multipart/form-data` request body.
 */
final class MultipartFilePart
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $filename,
        public readonly StreamInterface $content,
        public readonly string $contentType = 'application/octet-stream',
    ) {
    }
}
