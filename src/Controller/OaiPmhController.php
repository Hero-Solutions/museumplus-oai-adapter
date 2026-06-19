<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Record;
use App\Oai\OaiPmhException;
use App\Repository\RecordRepository;
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
        private readonly RecordRepository $records,
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

        if ($identifier !== null && $this->records->findOneByOaiIdentifier($identifier) === null) {
            throw new OaiPmhException('idDoesNotExist', 'No record exists for the given identifier.');
        }

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
        $metadataPrefix = $this->requiredParameter($request, 'metadataPrefix');
        $identifier = $this->requiredParameter($request, 'identifier');
        $this->assertSupportedMetadataPrefix($metadataPrefix);

        $record = $this->records->findOneByOaiIdentifier($identifier);

        if ($record === null) {
            throw new OaiPmhException('idDoesNotExist', 'No record exists for the given identifier.');
        }

        $getRecord = $this->appendElement($document->documentElement, 'GetRecord');
        $this->appendRecord($getRecord, $record, true);
    }

    private function appendHarvest(DOMDocument $document, Request $request, bool $includeMetadata): void
    {
        $verb = $includeMetadata ? 'ListRecords' : 'ListIdentifiers';
        $state = $this->harvestState($request, $verb);
        $total = $this->records->countForHarvest($state['from'], $state['until'], $state['set']);

        if ($total === 0) {
            throw new OaiPmhException('noRecordsMatch', 'No records match the request.');
        }

        $records = $this->records->findForHarvest($state['from'], $state['until'], $state['set'], self::PAGE_SIZE, $state['offset']);

        if ($records === []) {
            throw new OaiPmhException('noRecordsMatch', 'No records match the request.');
        }

        $list = $this->appendElement($document->documentElement, $verb);

        foreach ($records as $record) {
            if ($includeMetadata) {
                $this->appendRecord($list, $record, true);
            } else {
                $this->appendHeader($list, $record);
            }
        }

        $nextOffset = $state['offset'] + count($records);

        if ($nextOffset < $total) {
            $token = $this->appendText($list, 'resumptionToken', $this->encodeToken([
                'verb' => $verb,
                'metadataPrefix' => self::METADATA_PREFIX,
                'from' => $state['fromRaw'],
                'until' => $state['untilRaw'],
                'set' => $state['set'],
                'offset' => $nextOffset,
            ]));
            $token->setAttribute('completeListSize', (string) $total);
            $token->setAttribute('cursor', (string) $state['offset']);
        }
    }

    private function appendRecord(DOMElement $parent, Record $record, bool $includeMetadata): void
    {
        $recordElement = $this->appendElement($parent, 'record');
        $this->appendHeader($recordElement, $record);

        if (!$includeMetadata) {
            return;
        }

        $metadata = $this->appendElement($recordElement, 'metadata');
        $payload = $this->loadPayload($metadata->ownerDocument, $record->getOaiXml());
        $metadata->appendChild($payload);
    }

    private function appendHeader(DOMElement $parent, Record $record): void
    {
        $header = $this->appendElement($parent, 'header');
        $this->appendText($header, 'identifier', $record->getOaiIdentifier());
        $this->appendText($header, 'datestamp', $this->formatDatestamp($record->getDatestamp()));
        $this->appendText($header, 'setSpec', $record->getSetSpec());
    }

    /**
     * @return array{
     *     from: DateTimeImmutable|null,
     *     until: DateTimeImmutable|null,
     *     fromRaw: string|null,
     *     untilRaw: string|null,
     *     set: string|null,
     *     offset: int
     * }
     */
    private function harvestState(Request $request, string $verb): array
    {
        $resumptionToken = $this->parameter($request, 'resumptionToken');

        if ($resumptionToken !== null) {
            $this->assertOnlyResumptionToken($request);
            $state = $this->decodeToken($resumptionToken);

            if (($state['verb'] ?? null) !== $verb || ($state['metadataPrefix'] ?? null) !== self::METADATA_PREFIX) {
                throw new OaiPmhException('badResumptionToken', 'Invalid resumption token.');
            }

            $fromRaw = $this->tokenString($state, 'from');
            $untilRaw = $this->tokenString($state, 'until');

            return [
                'from' => $this->parseDate($fromRaw, false),
                'until' => $this->parseDate($untilRaw, true),
                'fromRaw' => $fromRaw,
                'untilRaw' => $untilRaw,
                'set' => $this->tokenString($state, 'set'),
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
