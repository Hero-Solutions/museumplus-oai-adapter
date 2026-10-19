<?php

declare(strict_types=1);

namespace App\Import;

use App\Mapping\RecordValues;
use App\Axiell\AxiellProfile;
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
        $profile = $this->museumProfile($record);
        if ($profile === null) return [];

        $flags = array_map(static fn (string $value): string => strtolower(trim($value)), $record->valuesByPath['WebPublicationTxt::webpublication'] ?? []);
        $sets = [];
        if (in_array('extranet', $flags, true)) $sets[] = $profile->value;
        if (in_array('vkc datahub', $flags, true)) $sets[] = 'vkc';

        return $sets;
    }

    public function museumProfile(RecordValues $record): ?AxiellProfile
    {
        return $this->groups->profile($record);
    }
}
