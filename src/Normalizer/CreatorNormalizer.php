<?php

declare(strict_types=1);

namespace App\Normalizer;

final class CreatorNormalizer
{
    /**
     * @return array{name: string, birth: string|null, death: string|null}
     */
    public function normalize(string $displayName): array
    {
        $displayName = trim($displayName);
        $dates = null;

        if (preg_match('/^(?<name>.+?)\s*\((?<dates>[^()]*)\)\s*$/u', $displayName, $matches) === 1) {
            $displayName = trim($matches['name']);
            $dates = trim($matches['dates']);
        }

        [$birth, $death] = $this->parseDates($dates);

        return [
            'name' => $displayName,
            'birth' => $birth,
            'death' => $death,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseDates(?string $dates): array
    {
        if ($dates === null || $dates === '') {
            return [null, null];
        }

        $datePattern = '\d{3,4}(?:-\d{2}(?:-\d{2})?)?';

        if (preg_match('/^(?<birth>'.$datePattern.')\s+-\s+(?<death>'.$datePattern.')$/u', $dates, $matches) === 1) {
            if ($matches['birth'] === $matches['death']) {
                return [$matches['birth'], null];
            }

            return [$matches['birth'], $matches['death']];
        }

        if (preg_match('/^(?<birth>'.$datePattern.')$/u', $dates, $matches) === 1) {
            return [$matches['birth'], null];
        }

        return [null, null];
    }
}
