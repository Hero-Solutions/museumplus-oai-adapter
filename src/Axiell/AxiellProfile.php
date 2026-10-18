<?php

declare(strict_types=1);

namespace App\Axiell;

enum AxiellProfile: string
{
    case Msk = 'mskObj';
    case Brugge = 'musea_brugge';
    case Muzee = 'muzee';
    case Vkc = 'vkc';

    public function identifierPrefix(): string
    {
        return $this === self::Msk ? 'mskobj' : $this->value;
    }

    public function identifier(string $priref): string
    {
        return $this->identifierPrefix().':'.$priref;
    }

    /** Keep profile differences explicit; never flatten every name/term node. */
    public function elements(array $elements, string $parent = ''): array
    {
        $result = [];
        $flat = $this === self::Brugge || $this === self::Muzee;
        $flatPaths = [
            'institution.name' => 'name', 'object_category' => 'term',
            'Object_name/object_name' => 'term', 'Material/material' => 'term',
            'Production/creator.role' => 'term', 'production.period' => 'term',
            'content.motif.general' => 'term', 'content.motif.specific' => 'term',
        ];
        $nested = $this === self::Msk ? [
            'collection'=>'term', 'Technique/technique'=>'term', 'acquisition.method'=>'term', 'acquisition.place'=>'term', 'Acquisition_source/acquisition.source'=>'name',
            'Inscription/inscription.type'=>'term', 'Inscription/inscription.position'=>'term', 'Production/production.place'=>'term', 'school_style'=>'term',
            'Owner_history/owner_hist.acquired_from'=>'name', 'Owner_history/owner_hist.acquisition.method'=>'term', 'Owner_history/owner_hist.place'=>'term',
        ] : [];
        if ($this !== self::Msk) $flatPaths += ['collection'=>'term','Technique/technique'=>'term','acquisition.method'=>'term','acquisition.place'=>'term','Acquisition_source/acquisition.source'=>'name'];
        foreach ($elements as $element) {
            $path = $parent === '' ? $element->name : $parent.'/'.$element->name;
            if (isset($nested[$path]) && $element->value !== null) {
                $result[] = AxiellElement::node($element->name, [AxiellElement::text($nested[$path], $element->value)]);
                continue;
            }
            if (isset($flatPaths[$path]) && ($flat || in_array($path, ['collection','Technique/technique','acquisition.method','acquisition.place','Acquisition_source/acquisition.source'], true)) && $element->children !== []) {
                foreach ($element->children as $child) {
                    if ($child->name === $flatPaths[$path]) {
                        $result[] = new AxiellElement($element->name, $child->value, $child->children);
                    } elseif ($child->name === 'object_category.notes') {
                        $result[] = $child;
                    }
                }
                continue;
            }
            if ($this === self::Msk && $path === 'Exhibition/exhibition' && $element->value !== null) {
                $result[] = AxiellElement::node('exhibition', [AxiellElement::text('title', $element->value)]);
                continue;
            }
            $result[] = new AxiellElement($element->name, $element->value, $this->elements($element->children, $path), $element->attributes);
        }

        return $result;
    }
}
