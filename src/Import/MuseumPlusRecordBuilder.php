<?php

declare(strict_types=1);

namespace App\Import;

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellProfile;
use App\Axiell\AxiellXmlRenderer;
use App\Mapping\MuseumPlusParsedRecord;

/** The same record conversion for API imports and rebuilding saved source XML. */
final readonly class MuseumPlusRecordBuilder
{
    public function __construct(
        private AxiellRecordMapper $mapper,
        private AxiellXmlRenderer $renderer,
        private PublicationSelector $publicationSelector = new PublicationSelector(),
    ) {
    }

    public function mappingVersion(): string
    {
        return 'profiles-20261019-msk-periods-v1:'.$this->publicationSelector->fingerprint().':'.$this->mapper->referenceFingerprint();
    }

    /** @param (callable(array{field: string, message: string}): void)|null $onWarning
     *  @return array<string, string|int|null>
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

        $sets = $this->publicationSelector->select($record->values);
        $setSpec = $sets[0] ?? $defaultSetSpec;
        $mapped = $this->mapper->map($record->values);
        if ($sets === [] && $onWarning !== null) $onWarning(['field' => 'MuseumTxt', 'message' => 'Unassigned museum group; record retained without publication: '.$institution]);
        $nativeProfile = AxiellProfile::tryFrom($setSpec);
        $nativeMapped = $nativeProfile !== null && $nativeProfile !== AxiellProfile::Vkc && in_array($setSpec, $sets, true)
            ? $this->mapper->map($record->values, $nativeProfile)
            : null;
        $nativeXml = $nativeMapped === null ? null : $this->renderer->renderRecord($nativeMapped);
        foreach (array_unique([...$mapped->warnings, ...($nativeMapped?->warnings ?? [])], SORT_REGULAR) as $warning) {
            if ($onWarning !== null) {
                $onWarning($warning);
            }
        }

        return [
            'museumplus_id' => $record->museumplusId,
            'raw_response_id' => $record->rawResponseId,
            'axiell_priref' => $this->mapper->priref($record->values),
            'oai_identifier' => $identifierPrefix.$record->museumplusId,
            'set_spec' => $setSpec,
            'datestamp' => $date,
            'object_number' => $record->values->objectNumber,
            'oai_xml' => $this->renderer->renderRecord($mapped),
            'oai_xml_native' => $nativeXml,
            'publish_msk' => (int) in_array('mskObj', $sets, true),
            'publish_brugge' => (int) in_array('musea_brugge', $sets, true),
            'publish_muzee' => (int) in_array('muzee', $sets, true),
            'publish_vkc' => (int) in_array('vkc', $sets, true),
            'museumplus_xml' => $record->museumplusXml,
            'created_at' => $date,
        ];
    }
}
