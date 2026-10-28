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
        return 'profiles-20261028-dimensions-materials-v1:'.$this->publicationSelector->fingerprint().':'.$this->mapper->referenceFingerprint();
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
        $nativeProfile = $this->publicationSelector->museumProfile($record->values);
        $setSpec = $nativeProfile?->value ?? $defaultSetSpec;
        $mapped = in_array('vkc', $sets, true) ? $this->mapper->map($record->values, AxiellProfile::Vkc) : null;
        if ($nativeProfile === null && $onWarning !== null) $onWarning(['field' => 'MuseumTxt', 'message' => 'Unassigned museum group; record retained without publication: '.$institution]);
        $nativeMapped = $nativeProfile !== null && in_array($nativeProfile->value, $sets, true)
            ? $this->mapper->map($record->values, $nativeProfile)
            : null;
        $nativeXml = $nativeMapped === null ? null : $this->renderer->renderRecord($nativeMapped);
        foreach (array_unique([...($mapped?->warnings ?? []), ...($nativeMapped?->warnings ?? [])], SORT_REGULAR) as $warning) {
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
            // The existing NOT NULL column stays empty when VKC publication is not allowed.
            'oai_xml' => $mapped === null ? '' : $this->renderer->renderRecord($mapped),
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
