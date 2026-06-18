<?php

declare(strict_types=1);

namespace App\Mapping;

final readonly class RecordValues
{
    /**
     * @param array<string, list<string>> $valuesByPath
     * @param list<array{field: string, error: string}> $invalidFragments
     */
    public function __construct(
        public string $sourceIdentifier,
        public ?string $objectNumber,
        public array $valuesByPath,
        public array $invalidFragments = [],
    ) {
    }
}
