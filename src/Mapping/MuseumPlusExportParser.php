<?php

declare(strict_types=1);

namespace App\Mapping;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class MuseumPlusExportParser
{
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
        $xpath = new DOMXPath($document);
        $records = [];

        foreach ($xpath->query('/*[local-name() = "ObjectList"]/*[local-name() = "Object"]') ?: [] as $objectNode) {
            if (!$objectNode instanceof DOMElement) {
                continue;
            }

            $valuesByPath = [];
            $invalidFragments = [];

            foreach ($objectNode->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }

                $sourceField = $child->localName;
                $fragment = trim($child->textContent);

                if ($fragment === '' || !str_contains($fragment, '<')) {
                    if ($fragment !== '') {
                        $valuesByPath[$sourceField][] = $fragment;
                    }

                    continue;
                }

                $parsed = $this->valueExtractor->extractFromFragment($fragment);

                if ($parsed['error'] !== null) {
                    $invalidFragments[] = ['field' => $sourceField, 'error' => $parsed['error']];

                    continue;
                }

                foreach ($parsed['values'] as $path => $values) {
                    $qualifiedPath = $sourceField.'::'.$path;

                    foreach ($values as $value) {
                        $valuesByPath[$qualifiedPath][] = $value;
                    }
                }
            }

            $museumplusId = $this->childText($objectNode, 'ID') ?? '';

            $records[] = new MuseumPlusParsedRecord(
                values: new RecordValues(
                    sourceIdentifier: $museumplusId,
                    objectNumber: $this->extractObjectNumber($objectNode),
                    valuesByPath: $valuesByPath,
                    invalidFragments: $invalidFragments,
                ),
                museumplusXml: trim($document->saveXML($objectNode) ?: ''),
                museumplusId: $museumplusId,
            );
        }

        return $records;
    }

    private function extractObjectNumber(DOMElement $objectNode): ?string
    {
        $fragment = $this->childText($objectNode, 'Objectnummer');

        if ($fragment === null || trim($fragment) === '') {
            return null;
        }

        $parsed = $this->valueExtractor->extractFromFragment($fragment);

        if ($parsed['error'] !== null) {
            return null;
        }

        $values = $parsed['values']['object_number'] ?? [];
        $value = trim((string) ($values[0] ?? ''));

        return $value === '' ? null : $value;
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

            throw new RuntimeException(sprintf('Could not parse MuseumPlus response: %s', $message));
        }

        return $document;
    }
}
