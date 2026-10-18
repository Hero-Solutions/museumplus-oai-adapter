<?php

declare(strict_types=1);

namespace App\Oai;

use App\Entity\Record;
use DateTimeImmutable;

interface HarvestRepository
{
    public function findOneByAxiellPriref(string $priref): ?Record;
    public function findEarliestDatestamp(): ?DateTimeImmutable;
    public function findSetSpecs(): array;
    public function findForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec, int $limit, int $offset): array;
    public function findHeadersForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec, int $limit, int $offset): array;
    public function countForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec): int;
}
