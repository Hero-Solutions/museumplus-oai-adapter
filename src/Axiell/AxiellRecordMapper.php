<?php

declare(strict_types=1);

namespace App\Axiell;

use App\Mapping\RecordValues;
use App\Mapping\DigitalReferenceValidator;
use App\Normalizer\CreatorNormalizer;
use App\Normalizer\DimensionNormalizer;
use App\Normalizer\LabelNormalizer;

final class AxiellRecordMapper
{
    public function __construct(
        private readonly CreatorNormalizer $creatorNormalizer,
        private readonly DimensionNormalizer $dimensionNormalizer,
        private readonly LabelNormalizer $labelNormalizer,
        private readonly DigitalReferenceValidator $digitalReferenceValidator = new DigitalReferenceValidator(),
    ) {
    }

    public function map(RecordValues $record): AxiellRecord
    {
        $elements = [];
        $warnings = [];

        $this->addText($elements, 'object_number', $this->first($record, 'Objectnummer::object_number'));
        $this->addText($elements, 'priref', $record->sourceIdentifier);
        $this->addNode($elements, 'institution.name', [
            AxiellElement::text('name', $this->first($record, 'Naam_bewaarinstelling::institution.name')),
        ]);
        $this->addText($elements, 'administration_name', $this->first($record, 'Bewaarinstelling_lokaal::administration_name'));
        foreach (array_unique($this->values($record, 'Naam_collectie::collection')) as $collection) {
            $elements[] = $this->authorityValue($record, 'collection', $collection);
        }
        $this->addObjectCategory($elements, $record);
        $this->addObjectNames($elements, $record);
        $this->addTitles($elements, $record);
        $this->addDescriptions($elements, $record);
        $workPids = $this->addDigitalReferences($elements, $record, $warnings);
        $this->addCurrentLocations($elements, $record, $warnings);
        $this->addDimensions($elements, $record);
        $this->addProduction($elements, $record, $warnings);
        $this->addSimpleRows($elements, 'Production_date', $record, [
            'production.date.start' => 'Productiedatum::Production/production.date.start',
            'production.date.end' => 'Productiedatum::Production/production.date.end',
        ]);
        foreach (array_unique($this->values($record, 'Periode::Production_period/production.period') ?: $this->values($record, 'Periode::production.period')) as $period) {
            $this->addNode($elements, 'production.period', [AxiellElement::text('term', $period)]);
        }
        $this->addMaterials($elements, $record);
        $this->addText($elements, 'physical_description', $this->first($record, 'Physical_description_NL'));
        $this->addText($elements, 'material_description', $this->first($record, 'Physical_description_NL'));
        $this->addTechniques($elements, $record);
        $this->addAcquisitions($elements, $record);
        $this->addText($elements, 'current_owner', $this->first($record, 'Herkomst_eigendom::current_owner'));
        $this->addAlternativeNumbers($elements, $record, $workPids, $warnings);
        $this->addText($elements, 'number_of_parts', $this->first($record, 'Aantal_onderdelen::number_of_parts'));
        $this->addText($elements, 'part', $this->first($record, 'Onderdeel_nummer::part'));
        $this->addText($elements, 'copy_number', $this->first($record, 'Exemplaar_nummer::copy_number'));
        $this->addText($elements, 'edition', $this->first($record, 'Editie::edition'));
        $this->addText($elements, 'print_state', $this->first($record, 'PrintState::print_state'));
        $this->addText($elements, 'credit_line', $this->first($record, 'CreditLine::credit_line'));
        $this->addMotif($elements, 'content.motif.general', $record, 'Term_motief_algemeen::content.motif.general');
        $this->addSpecificMotifs($elements, $record);
        $this->addText($elements, 'school_style', $this->first($record, 'Term_stijl::school_style'));
        $this->addText($elements, 'notes', $this->first($record, 'Opmerkingen::notes'));
        $this->addText($elements, 'title.notes', $this->first($record, 'Titel_opmerkingen::title.notes'));
        $this->addSimpleRows($elements, 'Condition', $record, [
            'condition' => 'Toestand::Condition/condition',
            'condition.date' => 'Toestand::Condition/condition.date',
            'condition.notes' => 'Toestand::Condition/condition.notes',
            'condition.part' => 'Toestand::Condition/condition.part',
            'condition.check.name' => 'Toestand::Condition/condition.check.name',
        ]);
        $this->addSimpleRows($elements, 'Inscription', $record, [
            'inscription.content' => 'Opschrift::Inscription/inscription.content',
            'inscription.type' => 'Opschrift::Inscription/inscription.type',
            'inscription.method' => 'Opschrift::Inscription/inscription.method',
            'inscription.position' => 'Opschrift::Inscription/inscription.position',
            'inscription.notes' => 'Opschrift::Inscription/inscription.notes',
            'inscription.description' => 'Opschrift::Inscription/inscription.description',
            'inscription.interpretation' => 'Opschrift::Inscription/inscription.interpretation',
            'inscription.language' => 'Opschrift::Inscription/inscription.language',
            'inscription.creator' => 'Opschrift::Inscription/inscription.creator',
            'inscription.creator.role' => 'Opschrift::Inscription/inscription.creator.role',
            'inscription.translation' => 'Opschrift::Inscription/inscription.translation',
            'inscription.date' => 'Opschrift::Inscription/inscription.date',
        ]);
        $this->addSimpleRows($elements, 'Rights', $record, [
            'rights.notes' => 'Rechten::Rights/rights.notes',
            'rights.type' => 'Rechten::Rights/rights.type',
            'rights.start' => 'Rechten::Rights/rights.start',
            'rights.end' => 'Rechten::Rights/rights.end',
            'rights.consent_status' => 'Rechten::Rights/rights.consent_status',
        ]);
        $this->addSimpleRows($elements, 'Reproduction', $record, [
            'reproduction.reference' => 'Reproductie::Reproduction/reproduction.reference',
            'reproduction.type' => 'Reproductie::Reproduction/reproduction.type',
            'reproduction.notes' => 'Reproductie::Reproduction/reproduction.notes',
            'reproduction.date' => 'Reproductie::Reproduction/reproduction.date',
            'reproduction.original_file_name' => 'Reproductie::Reproduction/reproduction.original_file_name',
        ]);
        $this->addSimpleRows($elements, 'Exhibition', $record, [
            'exhibition' => 'Tentoonstelling::Exhibition/exhibition',
            'exhibition.reference_number' => 'Tentoonstelling::Exhibition/exhibition.reference_number',
            'exhibition.catalogue_number' => 'Tentoonstelling::Exhibition/exhibition.catalogue_number',
            'exhibition.date.start' => 'Tentoonstelling::Exhibition/exhibition.date.start',
            'exhibition.date.end' => 'Tentoonstelling::Exhibition/exhibition.date.end',
            'exhibition.lref' => 'Tentoonstelling::Exhibition/exhibition.lref',
        ]);
        $this->addSimpleRows($elements, 'Associated_person', $record, [
            'association.person' => 'Associatie_persoon::Association_person/association.person',
            'association.person.association' => 'Associatie_persoon::Association_person/association.person.association',
            'association.person.date.start' => 'Associatie_persoon::Association_person/association.person.date.start',
            'association.person.date.end' => 'Associatie_persoon::Association_person/association.person.date.end',
            'association.person.note' => 'Associatie_persoon::Association_person/association.person.note',
        ]);

        return new AxiellRecord($record->objectNumber, $record->sourceIdentifier, $elements, $warnings);
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addObjectCategory(array &$elements, RecordValues $record): void
    {
        $rows = $this->rows($record, [
            'term' => 'Term_objectcategorie::Object_category/object_category',
            'notes' => 'Term_objectcategorie::Object_category/object_category.notes',
        ]);
        foreach ($rows as $row) {
            $this->addNode($elements, 'object_category', [
                ...$this->texts('term', $row['term']),
                // A record note stays attached to its category, but is not a translation.
                ...$this->texts('object_category.notes', $row['notes']),
            ]);
        }
        if ($rows === []) {
            foreach (array_unique($this->values($record, 'Term_objectcategorie::object_category')) as $category) {
                $this->addNode($elements, 'object_category', [AxiellElement::text('term', $category)]);
            }
        }
        $this->addText($elements, 'object_category.lref', $this->first($record, 'Term_objectcategorie_uri::object_category.uri'));
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addObjectNames(array &$elements, RecordValues $record): void
    {
        foreach ($this->rows($record, [
            'term' => 'Term_objectnaam::Object_name/object_name',
            'notes' => 'Term_objectnaam::Object_name/object_name.notes',
        ]) as $row) {
            $this->addNode($elements, 'Object_name', [
                AxiellElement::node('object_name', $this->texts('term', $row['term'])),
                ...$this->texts('object_name.notes', $row['notes']),
            ]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addTitles(array &$elements, RecordValues $record): void
    {
        $this->addSimpleRows($elements, 'Title', $record, [
            'title' => 'Titel_nl::Title/title',
            'title.type' => 'Titel_nl::Title/title.type',
            'title.language' => 'Titel_nl::Title/title.language',
        ]);
        $this->addSimpleRows($elements, 'Titel_translation', $record, [
            'title.translation' => 'Vertaalde_titel::Title/title',
            'title.language' => 'Vertaalde_titel::Title/title.language',
            'title.type' => 'Vertaalde_titel::Title/title.type',
        ]);
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addDescriptions(array &$elements, RecordValues $record): void
    {
        $this->addSimpleRows($elements, 'Description', $record, [
            'description' => 'Beschrijving::Description/description',
            'description.name' => 'Beschrijving::Description/description.name',
            'description.date' => 'Beschrijving::Description/description.date',
        ]);
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addDigitalReferences(array &$elements, RecordValues $record, array &$warnings): array
    {
        $workPids = [];
        foreach ($this->rows($record, [
            'url' => 'Persistente_uri::Digital_reference/digital_reference',
            'description' => 'Persistente_uri::Digital_reference/digital_reference.description',
        ]) as $row) {
            $description = $row['description'][0] ?? '';
            if (count($row['description']) > 1) {
                $warnings[] = ['field' => 'Persistente_uri', 'message' => 'Multiple identifier types in one reference; omitted pending review.'];
                continue;
            }
            foreach ($row['url'] as $url) {
                $problem = $this->digitalReferenceValidator->problem($url, $description, $this->values($record, 'Objectnummer::object_number'));
                if ($problem !== null) {
                    $warnings[] = ['field' => 'Persistente_uri', 'message' => $description.': '.$problem];
                    continue;
                }
                $this->addNode($elements, 'Digital_reference', [
                    AxiellElement::text('digital_reference', $url),
                    AxiellElement::text('digital_reference.description', $description),
                ]);
                if ($description === 'workpid') {
                    $workPids[] = $url;
                }
            }
        }

        return array_values(array_unique($workPids));
    }

    /** @param list<string> $workPids */
    private function addAlternativeNumbers(array &$elements, RecordValues $record, array $workPids, array &$warnings): void
    {
        $existingWorkPids = [];
        foreach ($this->rows($record, [
            'alternative_number' => 'Alternatief_nummer::Alternative_number/alternative_number',
            'alternative_number.type' => 'Alternatief_nummer::Alternative_number/alternative_number.type',
            'alternative_number.date' => 'Alternatief_nummer::Alternative_number/alternative_number.date',
            'alternative_number.institution' => 'Alternatief_nummer::Alternative_number/alternative_number.institution',
        ]) as $row) {
            $pidTypes = array_intersect($row['alternative_number.type'], ['workpid', 'datapid', 'representationpid']);
            if ($pidTypes !== []) {
                if (count($row['alternative_number.type']) !== 1) {
                    $warnings[] = ['field' => 'Alternatief_nummer', 'message' => 'Ambiguous identifier type; reference omitted pending review.'];
                    continue;
                }
                $type = $row['alternative_number.type'][0];
                $valid = [];
                foreach ($row['alternative_number'] as $url) {
                    $problem = $this->digitalReferenceValidator->problem($url, $type, $this->values($record, 'Objectnummer::object_number'));
                    if ($problem !== null) {
                        $warnings[] = ['field' => 'Alternatief_nummer', 'message' => $type.': '.$problem];
                    } else {
                        $valid[] = $url;
                    }
                }
                if ($valid === []) {
                    continue;
                }
                $row['alternative_number'] = $valid;
                if ($type === 'workpid') {
                    $existingWorkPids = [...$existingWorkPids, ...$valid];
                }
            }
            $children = [];
            foreach ($row as $field => $values) {
                $children = [...$children, ...$this->texts($field, $values)];
            }
            $this->addNode($elements, 'Alternative_number', $children);
        }
        // Both MSK DataHub fixes read workPIDs here, not from Digital_reference.
        if ($this->isMsk($record)) {
            foreach (array_diff($workPids, $existingWorkPids) as $url) {
                $this->addNode($elements, 'Alternative_number', [
                    AxiellElement::text('alternative_number', $url),
                    AxiellElement::text('alternative_number.type', 'workpid'),
                ]);
            }
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addCurrentLocations(array &$elements, RecordValues $record, array &$warnings): void
    {
        $rows = $this->rows($record, [
            'value' => 'Huidige_standplaats::Current_location/current_location',
            'context' => 'Huidige_standplaats::Current_location/current_location.context',
            'op' => 'Huidige_standplaats::Current_location/op',
        ]);

        foreach ($rows as $row) {
            $public = [];
            foreach ($row['op'] as $value) {
                $label = $this->labelNormalizer->publicLocation($value);
                if ($label === null) {
                    $warnings[] = ['field' => 'Huidige_standplaats', 'message' => 'Unrecognised public location omitted: '.$value];
                } else {
                    $public[] = $label;
                }
            }
            $this->addNode($elements, 'Current_location', [
                AxiellElement::node('current_location', [
                    ...$this->texts('name', $row['value']),
                    ...$this->texts('op', $public),
                ]),
                ...$this->texts('current_location.context', $row['context']),
            ]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addDimensions(array &$elements, RecordValues $record): void
    {
        foreach ($this->rows($record, [
            'value' => 'Afmeting::Dimension/dimension.value',
            'unit' => 'Afmeting::Dimension/dimension.unit',
            'part' => 'Afmeting::Dimension/dimension.part',
            'type' => 'Afmeting::Dimension/dimension.type',
            'precision' => 'Afmeting::Dimension/dimension.precision',
        ]) as $row) {
            foreach ($row['value'] as $value) {
                foreach ($this->dimensionNormalizer->normalize([$value], $row['unit'], $row['part']) as $dimension) {
                    $this->addNode($elements, 'Dimension', [
                        AxiellElement::text('dimension.type', $row['type'][0] ?? $dimension['type']),
                        AxiellElement::text('dimension.unit', $dimension['unit']),
                        AxiellElement::text('dimension.value', $dimension['value']),
                        AxiellElement::text('dimension.part', $dimension['part']),
                        ...$this->texts('dimension.precision', $row['precision']),
                    ]);
                }
            }
        }

        $this->addText($elements, 'dimension.free', $this->first($record, 'Afmeting::dimension.free'));
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addProduction(array &$elements, RecordValues $record, array &$warnings): void
    {
        $paths = [
            'name' => 'Naam_vervaardiger::Production/creator',
            'role' => 'Naam_vervaardiger::Production/creator.role',
            'qualifier' => 'Naam_vervaardiger::Production/creator.qualifier',
        ];
        foreach (['birth', 'death'] as $event) {
            foreach (['start', 'end', 'precision'] as $part) {
                $paths[$event.'.date.'.$part] = 'Naam_vervaardiger::Production/'.$event.'.date.'.$part;
            }
        }
        foreach ($this->rows($record, $paths) as $row) {
            $creator = $this->texts('name', $row['name']);
            $dates = [];
            foreach (['birth', 'death'] as $event) {
                foreach (['start', 'end'] as $part) {
                    $field = $event.'.date.'.$part;
                    $source = $row[$field] === [] ? null : implode(';', $row[$field]);
                    $dates[$field] = $this->creatorNormalizer->lifeDate($source);
                    if ($source !== null && $dates[$field] === null) {
                        $warnings[] = ['field' => 'Naam_vervaardiger', 'message' => 'Ambiguous or invalid '.$field.' omitted for '.implode(', ', $row['name']).': '.$source];
                    }
                    $this->addText($creator, $field, $dates[$field]);
                }
                $creator = [...$creator, ...$this->texts($event.'.date.precision', $row[$event.'.date.precision'])];
            }
            $this->addNode($elements, 'Production', [
                AxiellElement::node('creator', $creator),
                AxiellElement::text('creator.date_of_birth', $dates['birth.date.start']),
                AxiellElement::text('creator.date_of_death', $dates['death.date.start']),
                AxiellElement::node('creator.role', $this->texts('term', $row['role'])),
                ...$this->texts('creator.qualifier', $row['qualifier']),
            ]);
        }
        // The source has no link between these places and individual creators.
        foreach (array_unique($this->values($record, 'Naam_plaats_vervaardiging::production.place')) as $place) {
            $this->addNode($elements, 'Production', [AxiellElement::text('production.place', $this->labelNormalizer->productionPlace($place))]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addMaterials(array &$elements, RecordValues $record): void
    {
        $rows = $this->rows($record, [
            'term' => 'Term_materiaal::Material/material',
            'part' => 'Term_materiaal::Material/material.part',
            'notes' => 'Term_materiaal::Material/material.notes',
        ]);

        foreach ($rows as $row) {
            $this->addNode($elements, 'Material', [
                AxiellElement::node('material', $this->texts('term', $row['term'])),
                ...$this->texts('material.part', $row['part']),
                ...$this->texts('material.notes', $row['notes']),
            ]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addTechniques(array &$elements, RecordValues $record): void
    {
        foreach ($this->rows($record, [
            'technique' => 'Term_techniek::Technique/technique',
            'technique.part' => 'Term_techniek::Technique/technique.part',
            'technique.notes' => 'Term_techniek::Technique/technique.notes',
        ]) as $row) {
            $children = [];
            foreach ($row['technique'] as $term) {
                $children[] = $this->authorityValue($record, 'technique', $term);
            }
            $this->addNode($elements, 'Technique', [
                ...$children,
                ...$this->texts('technique.part', $row['technique.part']),
                ...$this->texts('technique.notes', $row['technique.notes']),
            ]);
        }
    }

    private function addAcquisitions(array &$elements, RecordValues $record): void
    {
        foreach ($this->rows($record, [
            'acquisition.source' => 'Verwerving::Acquisition_source/acquisition.source',
            'acquisition.method' => 'Verwerving::Acquisition_source/acquisition.method',
            'acquisition.date' => 'Verwerving::Acquisition_source/acquisition.date',
            'acquisition.place' => 'Verwerving::Acquisition_source/acquisition.place',
        ]) as $row) {
            $children = [];
            foreach ($row as $field => $values) {
                foreach ($values as $value) {
                    if ($field === 'acquisition.source') {
                        $children[] = $this->authorityValue($record, $field, $value, 'name');
                    } else {
                        $this->addText($children, $field, $value);
                        // Existing DataHub mappings consume these at record level.
                        $elements[] = $field === 'acquisition.date'
                            ? AxiellElement::text($field, $value)
                            : $this->authorityValue($record, $field, $value);
                    }
                }
            }
            $this->addNode($elements, 'Acquisition_source', $children);
        }
    }

    /** MSK's local DataHub mapping expects authority nodes for these fields. */
    private function authorityValue(RecordValues $record, string $name, string $value, string $child = 'term'): ?AxiellElement
    {
        return $this->isMsk($record)
            ? AxiellElement::node($name, [AxiellElement::text($child, $value)])
            : AxiellElement::text($name, $value);
    }

    private function isMsk(RecordValues $record): bool
    {
        return str_contains(strtolower($this->first($record, 'Naam_bewaarinstelling::institution.name') ?? ''), 'schone kunsten gent');
    }

    private function addSpecificMotifs(array &$elements, RecordValues $record): void
    {
        $rows = $this->rows($record, [
            'term' => 'Term_motief_specifiek::Content_motif_specific/content.motif.specific',
            'broader_term' => 'Term_motief_specifiek::Content_motif_specific/broader_term',
        ]);
        foreach ($rows as $row) {
            $this->addNode($elements, 'content.motif.specific', [
                ...$this->texts('term', $row['term']),
                ...$this->texts('broader_term', $row['broader_term']),
            ]);
        }
        if ($rows === []) {
            $this->addMotif($elements, 'content.motif.specific', $record, 'Term_motief_specifiek::content.motif.specific');
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addMotif(array &$elements, string $elementName, RecordValues $record, string $path): void
    {
        foreach ($this->values($record, $path) as $value) {
            $this->addNode($elements, $elementName, [
                AxiellElement::text('term', $value),
            ]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     * @param array<string, string> $pathsByElementName
     */
    private function addSimpleRows(array &$elements, string $rootName, RecordValues $record, array $pathsByElementName): void
    {
        foreach ($this->rows($record, $pathsByElementName) as $row) {
            $children = [];

            foreach ($row as $elementName => $values) {
                $children = [...$children, ...$this->texts($elementName, $values)];
            }

            $this->addNode($elements, $rootName, $children);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     * @param list<AxiellElement|null> $children
     */
    private function addNode(array &$elements, string $name, array $children): void
    {
        $element = AxiellElement::node($name, $children);

        if ($element !== null) {
            $elements[] = $element;
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addText(array &$elements, string $name, ?string $value): void
    {
        $element = AxiellElement::text($name, $value);

        if ($element !== null) {
            $elements[] = $element;
        }
    }

    private function first(RecordValues $record, string $path): ?string
    {
        return $this->values($record, $path)[0] ?? null;
    }

    /** @param list<string> $values
     *  @return list<AxiellElement>
     */
    private function texts(string $name, array $values): array
    {
        return array_values(array_filter(array_map(static fn (string $value): ?AxiellElement => AxiellElement::text($name, $value), $values)));
    }

    /**
     * @return list<string>
     */
    private function values(RecordValues $record, string $path): array
    {
        return array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), $record->valuesByPath[$path] ?? []),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @param array<string, string> $pathsByName
     *
     * @return list<array<string, list<string>>>
     */
    private function rows(RecordValues $record, array $pathsByName): array
    {
        $firstPath = reset($pathsByName);
        $group = substr($firstPath, 0, (int) strrpos($firstPath, '/'));
        $rows = [];
        $seen = [];
        foreach ($record->groupsByPath[$group] ?? [] as $sourceRow) {
            $row = [];
            foreach ($pathsByName as $name => $path) {
                $row[$name] = $sourceRow[$path] ?? [];
            }
            if (array_filter($row) === []) {
                continue;
            }

            $key = serialize($row);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = $row;
        }

        return $rows;
    }
}
