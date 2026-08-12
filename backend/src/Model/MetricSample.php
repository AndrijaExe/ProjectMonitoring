<?php

declare(strict_types=1);

namespace App\Model;

final readonly class MetricSample
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_.]{0,63}$/';
    public const MAX_TAGS = 8;
    public const MAX_TAG_LENGTH = 64;

    /**
     * @param array<string, string> $tags
     */
    public function __construct(
        public GameId $gameId,
        public string $name,
        public float $value,
        public array $tags,
        public \DateTimeImmutable $recordedAt,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Metric name must be dotted lowercase (max 64 chars).');
        }

        if (!is_finite($value)) {
            throw new \InvalidArgumentException('Metric value must be a finite number.');
        }

        if (count($tags) > self::MAX_TAGS) {
            throw new \InvalidArgumentException('A metric may have at most 8 tags.');
        }

        foreach ($tags as $key => $tagValue) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) !== 1) {
                throw new \InvalidArgumentException('Tag keys must be lowercase identifiers.');
            }
            if (mb_strlen($tagValue) > self::MAX_TAG_LENGTH) {
                throw new \InvalidArgumentException('Tag values must be at most 64 characters.');
            }
        }
    }
}
