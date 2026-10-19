<?php

declare(strict_types=1);

namespace App\Axiell;

use App\Mapping\RecordValues;
use RuntimeException;

final class MuseumGroups
{
    private array $groups;

    public function __construct(string $file = __DIR__.'/../../config/museum-groups.php')
    {
        $this->groups = require $file;
        $seen = [];
        foreach ($this->groups as $set => $group) {
            if (AxiellProfile::tryFrom($set) === null || $set === 'vkc') throw new RuntimeException('Invalid museum group.');
            foreach ($group['institutions'] as $name) {
                if (isset($seen[$name])) throw new RuntimeException('Ambiguous institution alias: '.$name);
                $seen[$name] = true;
            }
        }
    }

    public function profile(RecordValues $record): ?AxiellProfile
    {
        $matches = [];
        foreach ($this->groups as $set => $group) {
            foreach ($record->valuesByPath['__orgUnit'] ?? [] as $unit) if (str_starts_with($unit, $group['org_prefix'])) $matches[$set] = true;
        }
        if (count($matches) === 1) return AxiellProfile::from(array_key_first($matches));
        if ($matches !== []) return null;
        $institution = trim($record->valuesByPath['Naam_bewaarinstelling::institution.name'][0] ?? '');
        if ($institution === '') {
            foreach ($this->groups as $set => $group) {
                if (isset($group['records_without_institution'][$record->sourceIdentifier])
                    && $group['records_without_institution'][$record->sourceIdentifier] === $record->objectNumber) return AxiellProfile::from($set);
            }
        }
        foreach ($this->groups as $set => $group) if (in_array($institution, $group['institutions'], true)) return AxiellProfile::from($set);
        return null;
    }

    public function name(AxiellProfile $profile): string { return $this->groups[$profile->value]['name']; }
    public function translatedName(AxiellProfile $profile, string $language): ?string { return $this->groups[$profile->value]['name_translations'][$language] ?? null; }
    public function fingerprint(): string { return hash('sha256', serialize($this->groups)); }
}
