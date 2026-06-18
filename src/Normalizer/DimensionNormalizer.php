<?php

declare(strict_types=1);

namespace App\Normalizer;

final class DimensionNormalizer
{
    private const TYPES_BY_COUNT = [
        2 => ['hoogte', 'breedte'],
        3 => ['hoogte', 'breedte', 'diepte'],
    ];

    /**
     * @param list<string> $values
     * @param list<string> $units
     * @param list<string> $parts
     *
     * @return list<array{type: string|null, value: string, unit: string|null, part: string|null}>
     */
    public function normalize(array $values, array $units = [], array $parts = []): array
    {
        $dimensions = [];

        foreach ($values as $index => $value) {
            $parsed = $this->parseValue($value, $units[$index] ?? null);
            $part = $parts[$index] ?? 'geheel';
            $types = self::TYPES_BY_COUNT[count($parsed['values'])] ?? [];

            foreach ($parsed['values'] as $valueIndex => $singleValue) {
                $dimensions[] = [
                    'type' => $types[$valueIndex] ?? null,
                    'value' => $singleValue,
                    'unit' => $parsed['unit'],
                    'part' => $part,
                ];
            }
        }

        return $dimensions;
    }

    /**
     * @return array{values: list<string>, unit: string|null}
     */
    private function parseValue(string $value, ?string $fallbackUnit): array
    {
        $value = trim($value);
        $unit = $this->clean($fallbackUnit);

        if (preg_match('/^(?<values>.+?)\s+(?<unit>[a-zA-Zµ]+)$/u', $value, $matches) === 1) {
            $value = trim($matches['values']);
            $unit = $matches['unit'];
        }

        $parts = preg_split('/\s*[x×]\s*/u', $value) ?: [$value];
        $parts = array_values(array_filter(array_map($this->clean(...), $parts)));

        return [
            'values' => $parts === [] ? [$value] : $parts,
            'unit' => $unit,
        ];
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
