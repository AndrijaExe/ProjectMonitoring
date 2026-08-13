<?php

declare(strict_types=1);

namespace App\Model;

final readonly class LogLine
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $message,
        public ?string $level = null,
        public ?string $type = null,
    ) {
    }

    /**
     * @return array{at: string, message: string, level: ?string, type: ?string}
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->format(DATE_ATOM),
            'message' => $this->message,
            'level' => $this->level,
            'type' => $this->type,
        ];
    }
}
