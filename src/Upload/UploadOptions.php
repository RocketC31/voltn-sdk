<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Upload;

use Closure;

/**
 * Options controlling a TUS large-file upload.
 */
final class UploadOptions
{
    /**
     * @param int $chunkSize maximum number of bytes sent per TUS `PATCH`
     *                       request
     * @param null|(Closure(int $bytesSent, int $totalBytes): void) $onProgress
     *        invoked after each successfully-sent chunk
     */
    public function __construct(
        public readonly int $chunkSize = 5 * 1024 * 1024,
        public readonly ?Closure $onProgress = null,
        public readonly string $contentType = 'application/octet-stream',
    ) {
    }
}
