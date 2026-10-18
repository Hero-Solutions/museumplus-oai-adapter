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
     * @return array{values: array<string, list<string>>, groups: array<string, list<array<string, list<string>>>>, error: string|null, repair: string|null}
     */
    public function extractFromFragment(string $fragment): array
    {
        $fragment = trim($fragment);

        if ($fragment === '') {
            return ['values' => [], 'groups' => [], 'error' => null, 'repair' => null];
        }

        [$document, $error] = $this->loadFragment($fragment);
        $repair = null;
        if ($error !== null) {
            $escaped = $this->escapeExportedText($fragment);
            if ($escaped !== $fragment) {
                [$repairedDocument, $repairError] = $this->loadFragment($escaped);
                if ($repairError === null) {
                    $document = $repairedDocument;
                    $repair = $error;
                    $error = null;
                }
            }
        }

        if ($error !== null) {
            return ['values' => [], 'groups' => [], 'error' => $error, 'repair' => null];
        }

        $groups = [];
        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                // Keep each repetition intact, including missing optional children.
                $row = [];
                $this->extractElement($child, $child->localName, $row);
                $groups[$child->localName][] = $row;
            }
        }

        return [
            'values' => $this->extractFromElementChildren($document->documentElement),
            'groups' => $groups,
            'error' => null,
            'repair' => $repair,
        ];
    }

    /** @return array{DOMDocument, string|null} */
    private function loadFragment(string $fragment): array
    {
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

            return [$document, $message];
        }

        return [$document, null];
    }

    private function escapeExportedText(string $fragment): string
    {
        // These source leaves contain vocabulary text, including literal guide terms
        // such as <koper en koperlegering>. Do not repair arbitrary malformed tags.
        $fragment = preg_replace_callback(
            '~<!\[CDATA\[.*?\]\]>|<!--.*?-->|<(object_category|object_name|material)>(\s*<[^<>]+>\s*)</\1>~su',
            static function (array $match): string {
                if (!isset($match[1])) {
                    return $match[0];
                }

                $label = trim($match[2]);
                if (preg_match('~^<[\p{L}\p{N}][^<>]*\s+[^<>]+>$~u', $label) !== 1 || strpbrk($label, "=/'\"!?") !== false) {
                    return $match[0];
                }

                return '<'.$match[1].'>'.str_replace(['<', '>'], ['&lt;', '&gt;'], $match[2]).'</'.$match[1].'>';
            },
            $fragment,
        ) ?? $fragment;

        // A literal comparison sign in known text leaves is text, not a broken XML tag.
        $fragment = preg_replace_callback(
            '~<!\[CDATA\[.*?\]\]>|<!--.*?-->|<(production\.notes|production\.date\.notes|production\.period\.notes|condition\.notes|exhibition|exhibition\.notes|title|title\.translation|title\.notes|inscription\.content|inscription\.notes|inscription\.description)>(.*?)</\1>~su',
            static function (array $m): string {
                if (!isset($m[1])) return $m[0];
                // Exported angle-quote notation, including names such as <Grand'Gard>.
                $quoted = preg_replace_callback(
                    '~<!\[CDATA\[.*?\]\]>|<!--.*?-->|<<[^<>]*>>|<[\p{L}\p{N}.-]+\'[\p{L}\p{N}.-]+>~su',
                    static fn (array $part): string => str_starts_with($part[0], '<!')
                        ? $part[0] : str_replace(['<', '>'], ['&lt;', '&gt;'], $part[0]),
                    $m[2],
                );
                $text = preg_replace_callback(
                    '~<!\[CDATA\[.*?\]\]>|<!--.*?-->|</?[A-Za-z_](?:"[^"]*"|\'[^\']*\'|[^\'">])*>|<(?!/?[A-Za-z_]|!|\?)~su',
                    static fn (array $part): string => $part[0] === '<' ? '&lt;' : $part[0],
                    $quoted,
                );
                return '<'.$m[1].'>'.$text.'</'.$m[1].'>';
            },
            $fragment,
        ) ?? $fragment;

        // Escape bare ampersands only in text. Preserve markup, attributes, CDATA,
        // comments and entity references; unknown entities must still fail parsing.
        $parts = preg_split('~(<!\[CDATA\[.*?\]\]>|<!--.*?-->|<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)~s', $fragment, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $fragment;
        }
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = preg_replace('~&(?![^\s<>&;]*;)|&(?=[^\s<>&;]*=)~u', '&amp;', $part) ?? $part;
            }
        }

        return implode('', $parts);
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
