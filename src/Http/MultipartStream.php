<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only {@see StreamInterface} that lazily concatenates a sequence of
 * other streams, reading through them in order.
 *
 * Used to build `multipart/form-data` bodies (boundary/header parts plus
 * the actual file content stream) without ever buffering the full file
 * content into memory: the file's own stream is read from directly, in
 * whatever chunk size the underlying PSR-18 client requests.
 */
final class MultipartStream implements StreamInterface
{
    /** @var list<StreamInterface> */
    private array $streams;

    private int $currentIndex = 0;

    private ?int $size;

    /**
     * @param list<StreamInterface> $streams
     */
    public function __construct(array $streams)
    {
        $this->streams = array_values($streams);
        $this->size = $this->computeSize();
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }
    }

    public function detach()
    {
        $this->streams = [];
        $this->size = null;

        return null;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        throw new RuntimeException('MultipartStream does not support tell().');
    }

    public function eof(): bool
    {
        if ($this->currentIndex >= count($this->streams)) {
            return true;
        }

        return $this->currentIndex === count($this->streams) - 1
            && $this->streams[$this->currentIndex]->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        throw new RuntimeException('MultipartStream is not seekable.');
    }

    public function rewind(): void
    {
        foreach ($this->streams as $stream) {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        }

        $this->currentIndex = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        throw new RuntimeException('MultipartStream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $result = '';

        while (strlen($result) < $length && $this->currentIndex < count($this->streams)) {
            $current = $this->streams[$this->currentIndex];

            if ($current->eof()) {
                ++$this->currentIndex;
                continue;
            }

            $chunk = $current->read($length - strlen($result));

            if ($chunk === '') {
                ++$this->currentIndex;
                continue;
            }

            $result .= $chunk;
        }

        return $result;
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $chunk = $this->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata($key = null)
    {
        return $key === null ? [] : null;
    }

    private function computeSize(): ?int
    {
        $total = 0;

        foreach ($this->streams as $stream) {
            $size = $stream->getSize();

            if ($size === null) {
                return null;
            }

            $total += $size;
        }

        return $total;
    }
}
