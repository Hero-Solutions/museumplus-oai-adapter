<?php

declare(strict_types=1);

namespace App\Axiell;

final readonly class AxiellRecord
{
    /**
     * @param list<AxiellElement> $elements
     */
    public function __construct(
        public ?string $objectNumber,
        public string $sourceIdentifier,
        public array $elements,
    ) {
    }
}
