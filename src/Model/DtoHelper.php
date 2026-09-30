<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

use DateTimeImmutable;
use Throwable;

/**
 * Small set of defensive scalar coercion helpers shared by the DTOs'
 * `fromArray()` factories. Voltn API responses are not guaranteed to
 * include every documented field on every response (and some are
 * documented as nullable, e.g. `lock`), so every accessor here degrades to
 * `null` (or a safe default) instead of throwing on a missing/unexpected
 * type.
 */
final class DtoHelper
{
    private function __construct()
    {
    }

    public static function nullableString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    public static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    public static function intOrString(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    public static function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    public static function boolOrDefault(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    public static function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<mixed>
     */
    public static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
