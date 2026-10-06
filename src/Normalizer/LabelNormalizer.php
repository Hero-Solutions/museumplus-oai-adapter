<?php

declare(strict_types=1);

namespace App\Normalizer;

final class LabelNormalizer
{
    /** Only known public labels; never substitute internal locations or packaging notes. */
    public function publicLocation(string $value): ?string
    {
        $value = trim($value);
        $allowed = [
            'Depot', 'In depot', 'Te zien op zaal', 'In bruikleen', 'In restauratie',
            'Niet gelokaliseerd', 'Langdurig op andere locatie',
            'Œuvre exposée en salle', 'Work on display',
            'Œuvre non exposée en salle actuellement', 'Work currently not on display',
            "Œuvre d'art en cours de restauration", 'Work in the course of a conservation treatment',
            'Œuvre non localisée', 'Work of art not located',
            'Œuvre actuellement en prêt', 'Work on loan',
            'Gruuthusemuseum', 'Museum Sint-Janshospitaal', 'Volkskundemuseum',
            'Stadhuis', 'Belfort', 'O.L.V. ter Potterie', 'Groeningemuseum',
        ];
        foreach ($allowed as $label) {
            if (mb_strtolower($value, 'UTF-8') === mb_strtolower($label, 'UTF-8')) {
                return $value;
            }
        }

        return preg_match('/^Nu te zien in (?:Mu\.ZEE|peiremuzee|de Venetiaanse Gaanderijen)$/iu', $value) === 1 ? $value : null;
    }

    public function productionPlace(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, ',')) {
            return trim((string) preg_replace('/,\s+.*$/u', '', $value));
        }

        return $value;
    }
}
