<?php

declare(strict_types=1);

namespace App\Mapping;

/** Local ownership checks for the documented museum URL shapes; never resolves a URL. */
final class DigitalReferenceValidator
{
    /** @param list<string> $objectNumbers */
    public function problem(string $url, string $description, array $objectNumbers): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !isset($parts['host'])) {
            return 'Invalid HTTP(S) identifier: '.$url;
        }

        $host = strtolower($parts['host']);
        $knownHost = in_array($host, ['museabrugge.be', 'www.museabrugge.be', 'collectie.museabrugge.be', 'mskgent.be', 'www.mskgent.be', 'muzee.be', 'www.muzee.be'], true);
        if (!$knownHost || preg_match('~^/collection/work/(id|data|representation)/([^/]+)/?$~', $parts['path'] ?? '', $match) !== 1) {
            // Opaque or historical identifiers cannot be checked against an inventory number.
            return null;
        }

        $labels = ['id' => 'workpid', 'data' => 'datapid', 'representation' => 'representationpid'];
        if ($description !== $labels[$match[1]]) {
            return 'Identifier type does not match its URL: '.$url;
        }
        $token = rawurldecode($match[2]);
        foreach ($objectNumbers as $number) {
            $slug = str_contains($host, 'museabrugge.be')
                ? str_replace(['.', '/', ' '], '_', $number)
                : str_replace('/', '_', $number);
            if ($token === $number || $token === $slug) {
                return null;
            }
        }

        return 'Identifier refers to another inventory number; omitted pending review: '.$url;
    }
}
