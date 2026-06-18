<?php

declare(strict_types=1);

namespace App\Axiell;

use App\Mapping\RecordValues;
use App\Normalizer\CreatorNormalizer;
use App\Normalizer\DimensionNormalizer;
use App\Normalizer\LabelNormalizer;

final class AxiellRecordMapper
{
    public function __construct(
        private readonly CreatorNormalizer $creatorNormalizer,
        private readonly DimensionNormalizer $dimensionNormalizer,
        private readonly LabelNormalizer $labelNormalizer,
    ) {
    }

    public function map(RecordValues $record): AxiellRecord
    {
        $elements = [];

        $this->addText($elements, 'object_number', $this->first($record, 'Objectnummer::object_number'));
        $this->addText($elements, 'priref', $record->sourceIdentifier);
        $this->addNode($elements, 'institution.name', [
            AxiellElement::text('name', $this->first($record, 'Naam_bewaarinstelling::institution.name')),
        ]);
        $this->addText($elements, 'administration_name', $this->first($record, 'Bewaarinstelling_lokaal::administration_name'));
        $this->addText($elements, 'collection', $this->first($record, 'Naam_collectie::collection'));
        $this->addObjectCategory($elements, $record);
        $this->addObjectNames($elements, $record);
        $this->addTitles($elements, $record);
        $this->addDescriptions($elements, $record);
        $this->addDigitalReferences($elements, $record);
        $this->addCurrentLocations($elements, $record);
        $this->addDimensions($elements, $record);
        $this->addProduction($elements, $record);
        $this->addSimpleRows($elements, 'Production_date', $record, [
            'production.date.start' => 'Productiedatum::Production/production.date.start',
            'production.date.end' => 'Productiedatum::Production/production.date.end',
        ]);
        $this->addNode($elements, 'production.period', [
            AxiellElement::text('term', $this->first($record, 'Periode::production.period')),
        ]);
        $this->addMaterials($elements, $record);
        $this->addText($elements, 'physical_description', $this->first($record, 'Physical_description_NL'));
        $this->addText($elements, 'material_description', $this->first($record, 'Physical_description_NL'));
        $this->addTechniques($elements, $record);
        $this->addSimpleRows($elements, 'Acquisition_source', $record, [
            'acquisition.source' => 'Verwerving::Acquisition_source/acquisition.source',
            'acquisition.method' => 'Verwerving::Acquisition_source/acquisition.method',
            'acquisition.date' => 'Verwerving::Acquisition_source/acquisition.date',
            'acquisition.place' => 'Verwerving::Acquisition_source/acquisition.place',
        ]);
        $this->addSimpleRows($elements, 'Alternative_number', $record, [
            'alternative_number' => 'Alternatief_nummer::Alternative_number/alternative_number',
            'alternative_number.type' => 'Alternatief_nummer::Alternative_number/alternative_number.type',
            'alternative_number.date' => 'Alternatief_nummer::Alternative_number/alternative_number.date',
            'alternative_number.institution' => 'Alternatief_nummer::Alternative_number/alternative_number.institution',
        ]);
        $this->addText($elements, 'number_of_parts', $this->first($record, 'Aantal_onderdelen::number_of_parts'));
        $this->addText($elements, 'part', $this->first($record, 'Onderdeel_nummer::part'));
        $this->addText($elements, 'copy_number', $this->first($record, 'Exemplaar_nummer::copy_number'));
        $this->addText($elements, 'edition', $this->first($record, 'Editie::edition'));
        $this->addText($elements, 'print_state', $this->first($record, 'PrintState::print_state'));
        $this->addText($elements, 'credit_line', $this->first($record, 'CreditLine::credit_line'));
        $this->addMotif($elements, 'content.motif.general', $record, 'Term_motief_algemeen::content.motif.general');
        $this->addMotif($elements, 'content.motif.specific', $record, 'Term_motief_specifiek::content.motif.specific');
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

        return new AxiellRecord($record->objectNumber, $record->sourceIdentifier, $elements);
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addObjectCategory(array &$elements, RecordValues $record): void
    {
        $this->addNode($elements, 'object_category', [
            AxiellElement::text('term', $this->first($record, 'Term_objectcategorie::object_category')),
        ]);
        $this->addText($elements, 'object_category.lref', $this->first($record, 'Term_objectcategorie_uri::object_category.uri'));
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addObjectNames(array &$elements, RecordValues $record): void
    {
        foreach ($this->values($record, 'Term_objectnaam::Object_name/object_name') as $value) {
            $this->addNode($elements, 'Object_name', [
                AxiellElement::node('object_name', [
                    AxiellElement::text('term', $value),
                ]),
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
        ]);
        $this->addSimpleRows($elements, 'Titel_translation', $record, [
            'title.translation' => 'Vertaalde_titel::Title/title',
            'title.language' => 'Vertaalde_titel::Title/title.language',
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
    private function addDigitalReferences(array &$elements, RecordValues $record): void
    {
        $this->addSimpleRows($elements, 'Digital_reference', $record, [
            'digital_reference' => 'Persistente_uri::Digital_reference/digital_reference',
            'digital_reference.description' => 'Persistente_uri::Digital_reference/digital_reference.description',
        ]);
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addCurrentLocations(array &$elements, RecordValues $record): void
    {
        $rows = $this->rows($record, [
            'value' => 'Huidige_standplaats::Current_location/current_location',
            'context' => 'Huidige_standplaats::Current_location/current_location.context',
        ]);

        foreach ($rows as $row) {
            $this->addNode($elements, 'Current_location', [
                AxiellElement::node('current_location', [
                    AxiellElement::text('name', $row['value']),
                    AxiellElement::text('op', $row['value']),
                ]),
                AxiellElement::text('current_location.context', $row['context']),
            ]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addDimensions(array &$elements, RecordValues $record): void
    {
        $dimensions = $this->dimensionNormalizer->normalize(
            $this->values($record, 'Afmeting::Dimension/dimension.value'),
            $this->values($record, 'Afmeting::Dimension/dimension.unit'),
            $this->values($record, 'Afmeting::Dimension/dimension.part'),
        );

        foreach ($dimensions as $dimension) {
            $this->addNode($elements, 'Dimension', [
                AxiellElement::text('dimension.type', $dimension['type']),
                AxiellElement::text('dimension.unit', $dimension['unit']),
                AxiellElement::text('dimension.value', $dimension['value']),
                AxiellElement::text('dimension.part', $dimension['part']),
            ]);
        }

        $this->addText($elements, 'dimension.free', $this->first($record, 'Afmeting::dimension.free'));
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addProduction(array &$elements, RecordValues $record): void
    {
        $creators = $this->values($record, 'Naam_vervaardiger::Production/creator');
        $roleTerms = $this->values($record, 'Naam_vervaardiger::Production/creator.qualifier');
        $qualifiers = $this->values($record, 'Naam_vervaardiger::Production/creator.role');
        $places = array_map(
            $this->labelNormalizer->productionPlace(...),
            $this->values($record, 'Naam_plaats_vervaardiging::production.place')
        );

        $count = max(count($creators), count($places));

        for ($index = 0; $index < $count; ++$index) {
            $creator = isset($creators[$index]) ? $this->creatorNormalizer->normalize($creators[$index]) : null;

            $this->addNode($elements, 'Production', [
                AxiellElement::node('creator', [
                    AxiellElement::text('name', $creator['name'] ?? null),
                ]),
                AxiellElement::text('creator.date_of_birth', $creator['birth'] ?? null),
                AxiellElement::text('creator.date_of_death', $creator['death'] ?? null),
                AxiellElement::node('creator.role', [
                    AxiellElement::text('term', $roleTerms[$index] ?? null),
                ]),
                AxiellElement::text('creator.qualifier', $qualifiers[$index] ?? null),
                AxiellElement::text('production.place', $places[$index] ?? null),
            ]);
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
                AxiellElement::node('material', [
                    AxiellElement::text('term', $row['term']),
                ]),
                AxiellElement::text('material.part', $row['part']),
                AxiellElement::text('material.notes', $row['notes']),
            ]);
        }
    }

    /**
     * @param list<AxiellElement> $elements
     */
    private function addTechniques(array &$elements, RecordValues $record): void
    {
        $this->addSimpleRows($elements, 'Technique', $record, [
            'technique' => 'Term_techniek::Technique/technique',
            'technique.part' => 'Term_techniek::Technique/technique.part',
            'technique.notes' => 'Term_techniek::Technique/technique.notes',
        ]);
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

            foreach ($row as $elementName => $value) {
                $children[] = AxiellElement::text($elementName, $value);
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
     * @return list<array<string, string|null>>
     */
    private function rows(RecordValues $record, array $pathsByName): array
    {
        $valuesByName = [];
        $max = 0;

        foreach ($pathsByName as $name => $path) {
            $valuesByName[$name] = $this->values($record, $path);
            $max = max($max, count($valuesByName[$name]));
        }

        $rows = [];
        $seen = [];

        for ($index = 0; $index < $max; ++$index) {
            $row = [];

            foreach ($valuesByName as $name => $values) {
                $row[$name] = $values[$index] ?? null;
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
