<?php

declare(strict_types=1);

namespace App\Normalizer;

final class DimensionNormalizer
{
    private const TYPES_BY_COUNT = [
        2 => ['hoogte', 'breedte'],
        3 => ['hoogte', 'breedte', 'diepte'],
    ];

    /** Recover only a complete, explicitly labelled h/b[/d] measurement, without extra context. */
    public function fromExplicitFreeText(?string $text): array
    {
        if ($text === null || preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)(?:\s*[x×]\s*(\d+(?:[.,]\d+)?))?\s*(mm|cm|m)\s*\(\s*h\s*[x×]\s*b\s*(?:[x×]\s*(d))?\s*\)\s*$/iu', $text, $m) !== 1) return [];
        $depth = ($m[3] ?? '') !== '';
        if ($depth !== (($m[5] ?? '') !== '')) return [];
        $result = [];
        foreach (self::TYPES_BY_COUNT[$depth ? 3 : 2] as $index => $type) $result[] = ['type'=>$type, 'value'=>str_replace(',', '.', $m[$index+1]), 'unit'=>strtolower($m[4])];
        return $result;
    }

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
