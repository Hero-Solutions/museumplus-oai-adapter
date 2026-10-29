<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Record;
use App\Axiell\AxiellProfile;
use App\Oai\HarvestRepository;
use App\Oai\OaiPmhException;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class OaiPmhController
{
    private const OAI_NAMESPACE = 'http://www.openarchives.org/OAI/2.0/';
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';
    private const OAI_SCHEMA = 'http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd';
    private const SCHEMA_LOCATION = self::OAI_NAMESPACE.' '.self::OAI_SCHEMA;
    private const METADATA_PREFIX = 'oai_adlib';
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly HarvestRepository $records,
    ) {
    }

    #[Route('/oai', name: 'oai_pmh', methods: ['GET', 'POST'])]
    #[Route('/oai/', name: 'oai_pmh_slash', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $verb = $this->parameter($request, 'verb');
        $document = $this->createDocument($request, $verb);

        try {
            match ($verb) {
                'Identify' => $this->appendIdentify($document, $request),
                'ListMetadataFormats' => $this->appendListMetadataFormats($document, $request),
                'ListSets' => $this->appendListSets($document),
                'GetRecord' => $this->appendGetRecord($document, $request),
                'ListIdentifiers' => $this->appendHarvest($document, $request, false),
                'ListRecords' => $this->appendHarvest($document, $request, true),
                default => throw new OaiPmhException('badVerb', 'Missing or unsupported OAI-PMH verb.'),
            };
        } catch (OaiPmhException $exception) {
            foreach (iterator_to_array($document->documentElement->childNodes) as $child) {
                if ($child instanceof DOMElement && !in_array($child->localName, ['responseDate', 'request'], true)) $document->documentElement->removeChild($child);
            }
            $this->appendError($document, $exception->getOaiCode(), $exception->getMessage());
        }

        return new Response(
            $document->saveXML(),
            Response::HTTP_OK,
            ['Content-Type' => 'text/xml; charset=UTF-8'],
        );
    }

    private function appendIdentify(DOMDocument $document, Request $request): void
    {
        $identify = $this->appendElement($document->documentElement, 'Identify');
        $this->appendText($identify, 'repositoryName', 'MuseumPlus OAI Adapter');
        $this->appendText($identify, 'baseURL', $this->baseUrl($request));
        $this->appendText($identify, 'protocolVersion', '2.0');
        $this->appendText($identify, 'adminEmail', 'noreply@vlaamsekunstcollectie.be');
        $this->appendText($identify, 'earliestDatestamp', $this->formatDatestamp($this->records->findEarliestDatestamp() ?? new DateTimeImmutable('@0')));
        $this->appendText($identify, 'deletedRecord', 'no');
        $this->appendText($identify, 'granularity', 'YYYY-MM-DDThh:mm:ssZ');
    }

    private function appendListMetadataFormats(DOMDocument $document, Request $request): void
    {
        $identifier = $this->parameter($request, 'identifier');

        if ($identifier !== null) $this->resolveIdentifier($identifier);

        $formats = $this->appendElement($document->documentElement, 'ListMetadataFormats');
        $format = $this->appendElement($formats, 'metadataFormat');
        $this->appendText($format, 'metadataPrefix', self::METADATA_PREFIX);
        $this->appendText($format, 'schema', self::OAI_SCHEMA);
        $this->appendText($format, 'metadataNamespace', self::OAI_NAMESPACE);
    }

    private function appendListSets(DOMDocument $document): void
    {
        $setSpecs = $this->records->findSetSpecs();

        if ($setSpecs === []) {
            throw new OaiPmhException('noSetHierarchy', 'This repository does not define sets.');
        }

        $listSets = $this->appendElement($document->documentElement, 'ListSets');

        foreach ($setSpecs as $setSpec) {
            $set = $this->appendElement($listSets, 'set');
            $this->appendText($set, 'setSpec', $setSpec);
            $this->appendText($set, 'setName', $setSpec);
        }
    }

    private function appendGetRecord(DOMDocument $document, Request $request): void
    {
        $this->assertSupportedMetadataPrefix($this->requiredParameter($request, 'metadataPrefix'));
        [$record, $profile] = $this->resolveIdentifier($this->requiredParameter($request, 'identifier'));
        $getRecord = $this->appendElement($document->documentElement, 'GetRecord');
        $this->appendRecord($getRecord, $record, $profile);
    }

    /** Each set is a distinct OAI item, so GetRecord never depends on an absent set argument. */
    private function appendHarvest(DOMDocument $document, Request $request, bool $includeMetadata): void
    {
        $verb = $includeMetadata ? 'ListRecords' : 'ListIdentifiers';
        $state = $this->harvestState($request, $verb);
        $set = $state['set'];
        $counts = [];
        foreach (AxiellProfile::cases() as $profile) {
            if ($set !== null && $set !== $profile->value) continue;
            $counts[$profile->value] = $this->records->countForHarvest($state['from'], $state['until'], $profile->value);
        }
        $total = array_sum($counts);
        if ($total === 0 || $state['offset'] >= $total) throw new OaiPmhException('noRecordsMatch', 'No records match the request.');
        $list = $this->appendElement($document->documentElement, $verb);
        $offset = $state['offset']; $pageCount = 0;
        foreach ($counts as $set => $count) {
            if ($offset >= $count) { $offset -= $count; continue; }
            $profile = AxiellProfile::from($set);
            $remaining = self::PAGE_SIZE - $pageCount;
            if ($includeMetadata) {
                $records = $this->records->findForHarvest($state['from'], $state['until'], $set, $remaining, $offset);
                foreach ($records as $record) $this->appendRecordValues($list, $record['axiellPriref'], $record['datestamp'], $profile, $record['xml']);
                $pageCount += count($records);
            } else {
                $headers = $this->records->findHeadersForHarvest($state['from'], $state['until'], $set, $remaining, $offset);
                foreach ($headers as $header) $this->appendHeaderValues($list, $this->publicIdentifier($header['axiellPriref'], $profile), $header['datestamp'], [$set]);
                $pageCount += count($headers);
            }
            $offset = 0;
            if ($pageCount >= self::PAGE_SIZE) break;
        }
        if ($pageCount === 0) throw new OaiPmhException('noRecordsMatch', 'No records match the request.');
        if ($state['offset'] + $pageCount < $total) {
            $token = $this->appendText($list, 'resumptionToken', $this->encodeToken([
                'version'=>3, 'verb'=>$verb, 'metadataPrefix'=>$state['metadataPrefix'],
                'from'=>$state['fromRaw'], 'until'=>$state['untilRaw'], 'set'=>$state['set'], 'offset'=>$state['offset']+$pageCount,
            ]));
            $token->setAttribute('completeListSize', (string)$total);
            $token->setAttribute('cursor', (string)$state['offset']);
        }
    }

    private function appendRecord(DOMElement $parent, Record $record, AxiellProfile $profile): void
    {
        if (!in_array($profile->value, $record->getSetSpecs(), true)) throw new OaiPmhException('cannotDisseminateFormat', 'This record is not published in the requested profile.');
        $xml = $profile === AxiellProfile::Vkc ? $record->getOaiXml() : $record->getOaiXmlNative();
        $this->appendRecordValues($parent, $record->getAxiellPriref(), $record->getDatestamp(), $profile, $xml);
    }

    private function appendRecordValues(DOMElement $parent, ?string $priref, DateTimeImmutable $datestamp, AxiellProfile $profile, ?string $xml): void
    {
        if ($xml === null) throw new OaiPmhException('cannotDisseminateFormat', 'The requested profile must first be rebuilt.');
        $element = $this->appendElement($parent, 'record');
        $this->appendHeaderValues($element, $this->publicIdentifier($priref, $profile), $datestamp, [$profile->value]);
        $metadata = $this->appendElement($element, 'metadata');
        $metadata->appendChild($this->loadPayload($metadata->ownerDocument, $xml));
    }

    /** @return array{Record, AxiellProfile} */
    private function resolveIdentifier(string $identifier): array
    {
        foreach (AxiellProfile::cases() as $profile) {
            $prefix = $profile->identifierPrefix().':';
            if (str_starts_with($identifier, $prefix)) {
                $record = $this->records->findOneByAxiellPriref(substr($identifier, strlen($prefix)));
                if ($record !== null && in_array($profile->value, $record->getSetSpecs(), true)) return [$record, $profile];
                break;
            }
        }

        throw new OaiPmhException('idDoesNotExist', 'No record exists for the given identifier.');
    }

    private function publicIdentifier(?string $priref, AxiellProfile $profile): string
    {
        if ($priref === null || $priref === '') {
            throw new OaiPmhException('cannotDisseminateFormat', 'The Axiell identifiers must first be rebuilt from saved source records.');
        }

        return $profile->identifier($priref);
    }

    private function appendHeaderValues(DOMElement $parent, string $identifier, DateTimeImmutable $datestamp, array $setSpecs): void
    {
        $header = $this->appendElement($parent, 'header');
        $this->appendText($header, 'identifier', $identifier);
        $this->appendText($header, 'datestamp', $this->formatDatestamp($datestamp));
        foreach ($setSpecs as $setSpec) $this->appendText($header, 'setSpec', $setSpec);
    }

    /**
     * @return array{
     *     from: DateTimeImmutable|null,
     *     until: DateTimeImmutable|null,
     *     fromRaw: string|null,
     *     untilRaw: string|null,
     *     set: string|null,
     *     metadataPrefix: string,
     *     offset: int
     * }
     */
    private function harvestState(Request $request, string $verb): array
    {
        $resumptionToken = $this->parameter($request, 'resumptionToken');

        if ($resumptionToken !== null) {
            $this->assertOnlyResumptionToken($request);
            $state = $this->decodeToken($resumptionToken);

            if (($state['version'] ?? null) !== 3 || ($state['verb'] ?? null) !== $verb || !is_string($state['metadataPrefix'] ?? null)) {
                throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
            }
            try {
                $this->assertSupportedMetadataPrefix($state['metadataPrefix']);
            } catch (OaiPmhException) {
                throw new OaiPmhException('badResumptionToken', 'Invalid metadata profile in resumption token.');
            }

            $fromRaw = $this->tokenString($state, 'from');
            $untilRaw = $this->tokenString($state, 'until');

            return [
                'from' => $this->parseDate($fromRaw, false),
                'until' => $this->parseDate($untilRaw, true),
                'fromRaw' => $fromRaw,
                'untilRaw' => $untilRaw,
                'set' => $this->tokenString($state, 'set'),
                'metadataPrefix' => $state['metadataPrefix'],
                'offset' => $this->positiveOffset($state['offset'] ?? null),
            ];
        }

        $metadataPrefix = $this->requiredParameter($request, 'metadataPrefix');
        $this->assertSupportedMetadataPrefix($metadataPrefix);

        $fromRaw = $this->parameter($request, 'from');
        $untilRaw = $this->parameter($request, 'until');

        return [
            'from' => $this->parseDate($fromRaw, false),
            'until' => $this->parseDate($untilRaw, true),
            'fromRaw' => $fromRaw,
            'untilRaw' => $untilRaw,
            'set' => $this->parameter($request, 'set'),
            'metadataPrefix' => $metadataPrefix,
            'offset' => 0,
        ];
    }

    private function createDocument(Request $request, ?string $verb): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS(self::OAI_NAMESPACE, 'OAI-PMH');
        $root->setAttributeNS(self::XSI_NAMESPACE, 'xsi:schemaLocation', self::SCHEMA_LOCATION);
        $document->appendChild($root);

        $this->appendText($root, 'responseDate', $this->formatDatestamp(new DateTimeImmutable('now', new DateTimeZone('UTC'))));

        $requestElement = $this->appendText($root, 'request', $this->baseUrl($request));

        foreach ($this->requestAttributes($request, $verb) as $name => $value) {
            $requestElement->setAttribute($name, $value);
        }

        return $document;
    }

    private function loadPayload(DOMDocument $target, string $xml): DOMNode
    {
        $source = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $source->loadXML(trim($xml), LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$source->documentElement instanceof DOMElement) {
            $message = $errors === [] ? 'Stored OAI XML is invalid.' : trim($errors[0]->message);

            throw new OaiPmhException('badArgument', $message);
        }

        return $this->cloneIntoOaiNamespace($target, $source->documentElement);
    }

    private function cloneIntoOaiNamespace(DOMDocument $target, DOMNode $source): DOMNode
    {
        if ($source instanceof DOMText) {
            return $target->createTextNode($source->nodeValue ?? '');
        }

        if (!$source instanceof DOMElement) {
            return $target->createTextNode($source->textContent);
        }

        $element = $target->createElementNS(self::OAI_NAMESPACE, $source->localName);

        foreach ($source->attributes ?? [] as $attribute) {
            if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                continue;
            }

            $element->setAttribute($attribute->nodeName, $attribute->nodeValue ?? '');
        }

        foreach ($source->childNodes as $child) {
            $element->appendChild($this->cloneIntoOaiNamespace($target, $child));
        }

        return $element;
    }

    private function appendError(DOMDocument $document, string $code, string $message): void
    {
        $error = $this->appendText($document->documentElement, 'error', $message);
        $error->setAttribute('code', $code);
    }

    private function appendElement(DOMElement $parent, string $name): DOMElement
    {
        $element = $parent->ownerDocument->createElementNS(self::OAI_NAMESPACE, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function appendText(DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $this->appendElement($parent, $name);
        $element->appendChild($parent->ownerDocument->createTextNode($value));

        return $element;
    }

    private function requiredParameter(Request $request, string $name): string
    {
        $value = $this->parameter($request, $name);

        if ($value === null) {
            throw new OaiPmhException('badArgument', sprintf('Missing required argument "%s".', $name));
        }

        return $value;
    }

    private function parameter(Request $request, string $name): ?string
    {
        $value = $request->query->get($name, $request->request->get($name));

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function requestAttributes(Request $request, ?string $verb): array
    {
        $attributes = [];

        foreach (['verb', 'identifier', 'metadataPrefix', 'from', 'until', 'set', 'resumptionToken'] as $name) {
            $value = $name === 'verb' ? $verb : $this->parameter($request, $name);

            if ($value !== null) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    private function assertSupportedMetadataPrefix(string $metadataPrefix): void
    {
        if ($metadataPrefix !== self::METADATA_PREFIX) {
            throw new OaiPmhException('cannotDisseminateFormat', 'Unsupported metadataPrefix.');
        }
    }

    private function assertOnlyResumptionToken(Request $request): void
    {
        foreach (['metadataPrefix', 'from', 'until', 'set', 'identifier'] as $name) {
            if ($this->parameter($request, $name) !== null) {
                throw new OaiPmhException('badArgument', 'resumptionToken cannot be combined with other arguments.');
            }
        }
    }

    private function parseDate(?string $value, bool $endOfDay): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return new DateTimeImmutable($value.($endOfDay ? ' 23:59:59' : ' 00:00:00'), new DateTimeZone('UTC'));
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1) {
                return new DateTimeImmutable($value);
            }
        } catch (Throwable) {
            throw new OaiPmhException('badArgument', 'Invalid date argument.');
        }

        throw new OaiPmhException('badArgument', 'Invalid date argument.');
    }

    private function formatDatestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function encodeToken(array $state): string
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeToken(string $token): array
    {
        $base64 = strtr($token, '-_', '+/');
        $base64 = str_pad($base64, (int) ceil(strlen($base64) / 4) * 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
        }

        try {
            $state = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
        }

        if (!is_array($state)) {
            throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function tokenString(array $state, string $name): ?string
    {
        if (!array_key_exists($name, $state) || $state[$name] === null) {
            return null;
        }

        if (!is_string($state[$name])) {
            throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
        }

        return $state[$name];
    }

    private function positiveOffset(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
        }

        return $value;
    }

    private function baseUrl(Request $request): string
    {
        return $request->getUriForPath($request->getPathInfo());
    }
}
