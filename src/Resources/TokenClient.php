<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use RocketC31\Voltn\Exception\UnexpectedResponseException;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\FileToken;
use RocketC31\Voltn\Model\FileTokenType;

/**
 * `$client->tokens()`: generate single-purpose tokens for a file, and the
 * browser-facing URLs that consume them, without exposing the caller's
 * own access token.
 */
final class TokenClient
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    public function createFileToken(int|string $fileId, FileTokenType $type = FileTokenType::Download): FileToken
    {
        $data = $this->transport->sendForJson(
            'POST',
            sprintf('/token/file/%s/%s', rawurlencode((string) $fileId), $type->value),
        );

        $token = FileToken::fromArray($data ?? [], $type);

        if ($token->getToken() === '') {
            throw new UnexpectedResponseException(sprintf(
                'Voltn returned no token for file %s (type "%s").',
                (string) $fileId,
                $type->value,
            ));
        }

        return $token;
    }

    /**
     * URL of the file's preview page, authenticated by a fresh preview
     * token. Meant to be used as an iframe `src` or opened in a new tab:
     * Voltn renders PDFs, images and audio/video itself, and redirects
     * office documents to the configured editing platform.
     */
    public function previewUrl(int|string $fileId): string
    {
        $token = $this->createFileToken($fileId, FileTokenType::Preview);

        return $this->buildUrl(
            sprintf('/preview/%s', rawurlencode((string) $fileId)),
            ['token' => $token->getToken()],
        );
    }

    /**
     * Direct download URL for the file, authenticated by a fresh download
     * token, so the browser fetches the content from Voltn without it
     * transiting through the calling server.
     */
    public function downloadUrl(int|string $fileId): string
    {
        $token = $this->createFileToken($fileId, FileTokenType::Download);

        return $this->buildUrl('/file/download', ['token' => $token->getToken()]);
    }

    /**
     * @param array<string, scalar> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        return (string) $this->transport->createRequest('GET', $path, $query)->getUri();
    }
}
