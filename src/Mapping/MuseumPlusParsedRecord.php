<?php

declare(strict_types=1);

namespace App\Mapping;

final readonly class MuseumPlusParsedRecord
{
    public function __construct(
        public RecordValues $values,
        public string $museumplusXml,
        public string $museumplusId,
    ) {
    }
}
