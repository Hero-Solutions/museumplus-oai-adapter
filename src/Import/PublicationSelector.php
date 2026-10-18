<?php

declare(strict_types=1);

namespace App\Import;

use App\Mapping\RecordValues;
use App\Axiell\MuseumGroups;

final class PublicationSelector
{
    public function __construct(private readonly MuseumGroups $groups = new MuseumGroups()) {}

    public function fingerprint(): string
    {
        return $this->groups->fingerprint();
    }

    public function select(RecordValues $record): array
    {
        $profile = $this->groups->profile($record);
        return $profile === null ? [] : [$profile->value, 'vkc'];
    }
}
