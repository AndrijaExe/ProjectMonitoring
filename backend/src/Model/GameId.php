<?php

declare(strict_types=1);

namespace App\Model;

final readonly class GameId
{
    public const PATTERN = '/^[a-z][a-z0-9-]{1,31}$/';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || preg_match(self::PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException('Game id must be 2–32 chars: lowercase letter, then letters, digits, or hyphens.');
        }

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
