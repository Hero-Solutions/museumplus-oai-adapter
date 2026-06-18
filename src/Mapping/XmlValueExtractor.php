<?php

declare(strict_types=1);

namespace App\Mapping;

use DOMDocument;
use DOMElement;

final class XmlValueExtractor
{
    /**
     * @return array<string, list<string>>
     */
    public function extractFromElementChildren(DOMElement $element): array
    {
        $values = [];

        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $this->extractElement($child, $child->localName, $values);
        }

        return $values;
    }

    /**
     * @return array{values: array<string, list<string>>, error: string|null}
     */
    public function extractFromFragment(string $fragment): array
    {
        $fragment = trim($fragment);

        if ($fragment === '') {
            return ['values' => [], 'error' => null];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?><root>'.$fragment.'</root>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            $message = $errors === [] ? 'Invalid XML fragment' : trim($errors[0]->message).' on line '.$errors[0]->line;

            return ['values' => [], 'error' => $message];
        }

        return [
            'values' => $this->extractFromElementChildren($document->documentElement),
            'error' => null,
        ];
    }

    /**
     * @param array<string, list<string>> $values
     */
    private function extractElement(DOMElement $element, string $path, array &$values): void
    {
        $text = trim($element->textContent);

        if ($text !== '') {
            $values[$path][] = $text;
        }

        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $this->extractElement($child, $path.'/'.$child->localName, $values);
        }
    }
}
