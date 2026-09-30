<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * A single-purpose token generated for a file via
 * `POST /token/file/(fileId)/(type)`. It grants one kind of access
 * (preview, edit or download) to one file, so it can be handed to a
 * browser without exposing the caller's own OAuth2 access token.
 *
 * Voltn only returns the token string itself: no expiry is documented.
 */
final class FileToken
{
    private function __construct(
        private readonly string $token,
        private readonly FileTokenType $type,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, FileTokenType $type): self
    {
        return new self(
            token: DtoHelper::nullableString($data['token'] ?? null) ?? '',
            type: $type,
        );
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getType(): FileTokenType
    {
        return $this->type;
    }
}
