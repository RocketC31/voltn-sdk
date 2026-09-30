<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\DownloadToken;

/**
 * `$client->tokens()`: generate short-lived, single-purpose tokens for a
 * file (e.g. a temporary download link) without exposing the caller's own
 * access token.
 */
final class TokenClient
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * @param string $type platform-defined token purpose, e.g. `download`
     */
    public function createFileToken(int|string $fileId, string $type = 'download'): DownloadToken
    {
        $data = $this->transport->sendForJson(
            'POST',
            sprintf('/token/file/%s/%s', rawurlencode((string) $fileId), rawurlencode($type)),
        );

        return DownloadToken::fromArray($data ?? []);
    }
}
