<?php

declare(strict_types=1);

namespace App\Normalizer;

final class CreatorNormalizer
{
    /** Collapse duplicate dates, or compatible year/month/day variants, without guessing. */
    public function lifeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $dates = array_values(array_unique(array_map('trim', explode(';', $value))));
        foreach ($dates as $date) {
            if (preg_match('/^(\d{3,4})(?:-(\d{2})(?:-(\d{2}))?)?$/D', $date, $parts) !== 1) {
                return null;
            }
            if (isset($parts[2]) && ((int) $parts[2] < 1 || (int) $parts[2] > 12)) {
                return null;
            }
            if (isset($parts[3]) && !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return null;
            }
        }
        usort($dates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($dates as $date) {
            if ($date !== $dates[0] && !str_starts_with($dates[0], $date.'-')) {
                return null;
            }
        }

        return $dates[0];
    }

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
