<?php

declare(strict_types=1);

namespace App\Normalizer;

final class DimensionNormalizer
{
    private const TYPES_BY_COUNT = [
        2 => ['hoogte', 'breedte'],
        3 => ['hoogte', 'breedte', 'diepte'],
    ];

    private const TYPES = ['hoogte', 'breedte', 'diepte', 'diameter', 'dikte', 'lengte', 'gewicht'];

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
    public function normalize(array $values, array $units = [], array $parts = [], ?string $type = null): array
    {
        $dimensions = [];
        $type = $this->clean($type);
        $explicitTypes = $type === null ? [] : preg_split('/\s*[x×]\s*/u', strtolower($type));
        $knownTypes = $explicitTypes !== [] && array_diff($explicitTypes, self::TYPES) === [];

        foreach ($values as $index => $value) {
            $parsed = $this->parseValue($value, $units[$index] ?? null);
            $part = $parts[$index] ?? 'geheel';
            $types = $knownTypes ? $explicitTypes : [];
            if ($type === null || strtolower($type) === 'afmetingen object') {
                $types = self::TYPES_BY_COUNT[count($parsed['values'])] ?? [];
            }

            // Only split when every value has a corresponding type, in source order.
            // Keep an ambiguous measurement together instead of guessing its axes.
            if (count($types) !== count($parsed['values'])) {
                $types = [$knownTypes && count($explicitTypes) === 1 ? $explicitTypes[0] : $type];
                $parsed['values'] = [$parsed['value']];
            }

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
     * @return array{value: string, values: list<string>, unit: string|null}
     */
    private function parseValue(string $value, ?string $fallbackUnit): array
    {
        $value = trim($value);
        $unit = $this->clean($fallbackUnit);

        $number = '[+-]?\d+(?:[.,]\d+)?';
        if (preg_match('/^(?<values>'.$number.'(?:\s*[x×]\s*'.$number.')*)\s*(?<unit>(?![xX]$)[a-zA-Zµ]+)?$/u', $value, $matches) !== 1) {
            return ['value' => $value, 'values' => [$value], 'unit' => $unit];
        }

        $value = $matches['values'];
        $unit = $this->clean($matches['unit'] ?? null) ?? $unit;

        return [
            'value' => $value,
            'values' => preg_split('/\s*[x×]\s*/u', $value) ?: [$value],
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
