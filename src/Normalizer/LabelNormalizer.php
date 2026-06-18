<?php

declare(strict_types=1);

namespace App\Normalizer;

final class LabelNormalizer
{
    public function productionPlace(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, ',')) {
            return trim((string) preg_replace('/,\s+.*$/u', '', $value));
        }

        return $value;
    }
}
