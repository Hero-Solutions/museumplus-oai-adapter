<?php

declare(strict_types=1);

namespace App\Import;

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Mapping\MuseumPlusParsedRecord;

/** The same record conversion for API imports and rebuilding saved source XML. */
final readonly class MuseumPlusRecordBuilder
{
    public function __construct(
        private AxiellRecordMapper $mapper,
        private AxiellXmlRenderer $renderer,
    ) {
    }

    /** @param (callable(array{field: string, message: string}): void)|null $onWarning
     *  @return array<string, string|null>
     */
    public function build(MuseumPlusParsedRecord $record, string $date, string $identifierPrefix, string $defaultSetSpec, ?callable $onWarning = null): array
    {
        $institution = '';
        foreach ($record->values->valuesByPath['Naam_bewaarinstelling::institution.name'] ?? [] as $value) {
            if (trim($value) !== '') {
                $institution = strtolower(trim($value));
                break;
            }
        }

        $setSpec = match (true) {
            str_contains($institution, 'schone kunsten gent') => 'msk_gent',
            str_contains($institution, 'mu.zee'), str_contains($institution, 'oostende') => 'muzee',
            str_contains($institution, 'musea brugge'), str_contains($institution, 'brugge') => 'musea_brugge',
            default => $defaultSetSpec,
        };

        $mapped = $this->mapper->map($record->values);
        foreach ($mapped->warnings as $warning) {
            if ($onWarning !== null) {
                $onWarning($warning);
            }
        }

        return [
            'museumplus_id' => $record->museumplusId,
            'oai_identifier' => $identifierPrefix.$record->museumplusId,
            'set_spec' => $setSpec,
            'datestamp' => $date,
            'object_number' => $record->values->objectNumber,
            'oai_xml' => $this->renderer->renderRecord($mapped),
            'museumplus_xml' => $record->museumplusXml,
            'created_at' => $date,
        ];
    }
}
