<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

use DateTimeImmutable;

/**
 * A file's lock, when it is currently locked. Voltn represents this
 * as either an object (this class) or `null` on the owning {@see File} —
 * see {@see File::getLock()}.
 */
final class LockInfo
{
    private function __construct(
        private readonly int|string|null $userId,
        private readonly ?string $userName,
        private readonly ?DateTimeImmutable $lockedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: DtoHelper::intOrString($data['user_id'] ?? $data['userId'] ?? null),
            userName: DtoHelper::nullableString($data['user_name'] ?? $data['userName'] ?? null),
            lockedAt: DtoHelper::nullableDate($data['locked_at'] ?? $data['lockedAt'] ?? null),
        );
    }

    public function getUserId(): int|string|null
    {
        return $this->userId;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getLockedAt(): ?DateTimeImmutable
    {
        return $this->lockedAt;
    }
}
