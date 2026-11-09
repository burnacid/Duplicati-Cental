<?php

namespace App\Enums;

/**
 * Single source of truth for how a Duplicati ParsedResult is shown.
 */
enum BackupStatus: string
{
    case Success = 'Success';
    case Warning = 'Warning';
    case Error = 'Error';
    case Fatal = 'Fatal';
    case Unknown = 'Unknown';
    case None = 'None';

    public static function fromResult(?string $parsedResult): self
    {
        return self::tryFrom((string) $parsedResult) ?? self::Unknown;
    }

    /**
     * Worst status of a set of ParsedResult values (None when empty).
     *
     * @param iterable<string|null|self> $results
     */
    public static function worstOf(iterable $results): self
    {
        $worst = self::None;

        foreach ($results as $result) {
            $status = $result instanceof self ? $result : self::fromResult($result);
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function severity(): int
    {
        return match ($this) {
            self::None => 0,
            self::Success => 1,
            self::Unknown => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Fatal => 5,
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Error || $this === self::Fatal;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No data',
            default => $this->value,
        };
    }

    /** Colour used in the status bars. */
    public function color(): string
    {
        return match ($this) {
            self::Success => '#5cdd8b',
            self::Warning => 'orange',
            self::Error, self::Fatal => 'red',
            self::Unknown => '#a1a1aa',
            self::None => '#808080',
        };
    }

    /** Colour name for <flux:badge>. */
    public function fluxColor(): string
    {
        return match ($this) {
            self::Success => 'green',
            self::Warning => 'amber',
            self::Error, self::Fatal => 'red',
            default => 'zinc',
        };
    }

    /** Tailwind classes for status badges. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Success => 'bg-green-100 text-green-800',
            self::Warning => 'bg-yellow-100 text-yellow-800',
            self::Error, self::Fatal => 'bg-red-100 text-red-800',
            default => 'bg-zinc-100 text-zinc-800',
        };
    }
}
