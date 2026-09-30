<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A minimal {@see StreamInterface} test double that reports itself as
 * non-seekable, to exercise code paths that must avoid retrying a request
 * whose body may have already been partially consumed (e.g. a large file
 * upload streamed from a non-rewindable source).
 */
final class NonSeekableStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private string $contents)
    {
    }

    public function __toString(): string
    {
        return $this->contents;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->contents);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('This stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('This stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('This stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $remaining = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $remaining;
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }
}
