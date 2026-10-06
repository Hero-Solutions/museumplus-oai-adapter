<?php

declare(strict_types=1);

namespace App\Mapping;

final readonly class RecordValues
{
    /**
     * @param array<string, list<string>> $valuesByPath
     * @param list<array{field: string, error: string}> $invalidFragments
     * @param list<array{field: string, error: string}> $repairedFragments
     * @param array<string, list<array<string, list<string>>>> $groupsByPath
     */
    public function __construct(
        public string $sourceIdentifier,
        public ?string $objectNumber,
        public array $valuesByPath,
        public array $invalidFragments = [],
        public array $repairedFragments = [],
        public array $groupsByPath = [],
    ) {
    }
}
