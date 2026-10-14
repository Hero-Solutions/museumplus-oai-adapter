<?php

declare(strict_types=1);

namespace App\Mapping;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class MuseumPlusExportParser
{
    // The revised export changes column names; keep the existing mapping paths.
    // Raw source XML and diagnostic field names retain the original names.
    private const FIELD_ALIASES = [
        'priref' => 'ID',
        'physical_description' => 'Physical_description_NL',
        'AcquisitionTxt' => 'Verwerving',
        'AdminNameTxt' => 'Bewaarinstelling_lokaal',
        'AlternativeNumberTxt' => 'Alternatief_nummer',
        'AssociationPersonTxt' => 'Associatie_persoon',
        'CollectionTxt' => 'Naam_collectie',
        'ConditionTxt' => 'Toestand',
        'MotifGeneralTxt' => 'Term_motief_algemeen',
        'MotifSpecificTxt' => 'Term_motief_specifiek',
        'CopyNumberTxt' => 'Exemplaar_nummer',
        'CreditLineTxt' => 'CreditLine',
        'CurrentLocationTxt' => 'Huidige_standplaats',
        'OwnershipTxt' => 'Herkomst_eigendom',
        'DescriptionTxt' => 'Beschrijving',
        'PIDsTxt' => 'Persistente_uri',
        'DimensionTxt' => 'Afmeting',
        'EditionTxt' => 'Editie',
        'ExhibitionTxt' => 'Tentoonstelling',
        'InscriptionTxt' => 'Opschrift',
        'MuseumTxt' => 'Naam_bewaarinstelling',
        'MaterialTxt' => 'Term_materiaal',
        'NotesTxt' => 'Opmerkingen',
        'NumberOfPartsTxt' => 'Aantal_onderdelen',
        'ObjectCategoryTxt' => 'Term_objectcategorie',
        'ObjectCategoryUri' => 'Term_objectcategorie_uri',
        'ObjectNameTxt' => 'Term_objectnaam',
        'NumberTxt' => 'Objectnummer',
        'PartTxt' => 'Onderdeel_nummer',
        'PrintStateTxt' => 'PrintState',
        'CreatorTxt' => 'Naam_vervaardiger',
        'ProductionPlaceTxt' => 'Naam_plaats_vervaardiging',
        'ProductionDateTxt' => 'Productiedatum',
        'PeriodTxt' => 'Periode',
        'ReproductionTxt' => 'Reproductie',
        'RightsTxt' => 'Rechten',
        'StyleTxt' => 'Term_stijl',
        'TechniqueTxt' => 'Term_techniek',
        'TranslatedTitleTxt' => 'Vertaalde_titel',
        'TitleTxt_nl' => 'Titel_nl',
        'TitleNotesTxt' => 'Titel_opmerkingen',
    ];

    public function __construct(
        private readonly XmlValueExtractor $valueExtractor,
    ) {
    }

    /**
     * @return list<MuseumPlusParsedRecord>
     */
    public function parse(string $xml): array
    {
        $document = $this->loadDocument($xml);

        if ($document->documentElement?->localName !== 'ObjectList') {
            throw new InvalidMuseumPlusResponse('Expected an ObjectList response from MuseumPlus. Refusing to treat an error page as the end of the import.', $xml);
        }

        $xpath = new DOMXPath($document);
        $objects = $xpath->query('/*[local-name() = "ObjectList"]/*[local-name() = "Object"]');
        $records = [];

        foreach ($objects ?: [] as $objectNode) {
            if (!$objectNode instanceof DOMElement) {
                continue;
            }

            $id = $this->childText($objectNode, 'ID');
            $priref = $this->childText($objectNode, 'priref');
            if ($id !== null && $priref !== null && $id !== $priref) {
                // A format/identity problem must stop the import, never auto-skip.
                throw new RuntimeException('MuseumPlus export contains conflicting ID and priref values. Check the export report before importing.');
            }
            $museumplusId = $id ?? $priref ?? '';

            $valuesByPath = [];
            $groupsByPath = [];
            $invalidFragments = [];
            $repairedFragments = [];

            foreach ($objectNode->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }

                $exportField = $child->localName;
                $sourceField = self::FIELD_ALIASES[$exportField] ?? $exportField;
                $fragment = trim($child->textContent);

                if ($fragment === '' || !str_contains($fragment, '<')) {
                    if ($fragment !== '') {
                        $valuesByPath[$sourceField][] = $fragment;
                    }

                    continue;
                }

                $parsed = $this->valueExtractor->extractFromFragment($fragment);

                if ($parsed['error'] !== null) {
                    $invalidFragments[] = ['field' => $exportField, 'error' => $parsed['error']];

                    continue;
                }

                if ($parsed['repair'] !== null) {
                    $repairedFragments[] = ['field' => $exportField, 'error' => $parsed['repair']];
                }

                foreach ($parsed['groups'] as $group => $rows) {
                    foreach ($rows as $row) {
                        $qualifiedRow = [];
                        foreach ($row as $path => $values) {
                            $qualifiedRow[$this->qualifiedPath($sourceField, $path)] = $values;
                        }
                        $groupsByPath[$this->qualifiedPath($sourceField, $group)][] = $qualifiedRow;
                    }
                }

                foreach ($parsed['values'] as $path => $values) {
                    $qualifiedPath = $this->qualifiedPath($sourceField, $path);

                    foreach ($values as $value) {
                        $valuesByPath[$qualifiedPath][] = $value;
                    }
                }
            }

            $records[] = new MuseumPlusParsedRecord(
                values: new RecordValues(
                    sourceIdentifier: $museumplusId,
                    objectNumber: $valuesByPath['Objectnummer::object_number'][0] ?? null,
                    valuesByPath: $valuesByPath,
                    invalidFragments: $invalidFragments,
                    repairedFragments: $repairedFragments,
                    groupsByPath: $groupsByPath,
                ),
                museumplusXml: trim($document->saveXML($objectNode) ?: ''),
                museumplusId: $museumplusId,
            );
        }

        if ($records !== [] && !array_any($records, static fn (MuseumPlusParsedRecord $record): bool => $record->museumplusId !== '')) {
            $fields = [];
            foreach ($objects->item(0)->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $fields[] = $child->localName;
                }
            }
            throw new RuntimeException(sprintf(
                'MuseumPlus returned %d objects without an ID or priref. Refusing to skip this batch. Check the export report. First object fields: %s.',
                count($records),
                implode(', ', array_slice(array_unique($fields), 0, 16)),
            ));
        }

        return $records;
    }

    private function qualifiedPath(string $sourceField, string $path): string
    {
        // ProductionDateTxt now wraps dates in Production_date, previously Production.
        if ($sourceField === 'Productiedatum' && ($path === 'Production_date' || str_starts_with($path, 'Production_date/'))) {
            $path = 'Production'.substr($path, strlen('Production_date'));
        }

        return $sourceField.'::'.$path;
    }

    private function childText(DOMElement $objectNode, string $childName): ?string
    {
        foreach ($objectNode->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $childName) {
                $value = trim($child->textContent);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }

    private function loadDocument(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $message = $errors === [] ? 'Could not parse XML' : trim($errors[0]->message);

            throw new InvalidMuseumPlusResponse(sprintf('Could not parse MuseumPlus response: %s', $message), $xml);
        }

        return $document;
    }
}
