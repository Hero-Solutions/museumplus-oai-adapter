<?php

declare(strict_types=1);

// In-memory repository and Request objects only; never send an HTTP request.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Axiell\AxiellProfile;
use App\Controller\OaiPmhController;
use App\Entity\Record;
use App\Oai\HarvestRepository;
use Symfony\Component\HttpFoundation\Request;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class FixtureRepository implements HarvestRepository
{
    public array $records = [];
    public function findOneByAxiellPriref(string $priref): ?Record
    {
        foreach ($this->records as $record) if ($record->getAxiellPriref() === $priref && $record->getSetSpecs() !== []) return $record;
        return null;
    }
    public function findEarliestDatestamp(): ?DateTimeImmutable { return new DateTimeImmutable('2026-10-16T00:00:00Z'); }
    public function findSetSpecs(): array { return array_values(array_filter(array_keys(Record::PUBLICATION_FIELDS), fn(string $set): bool => $this->countForHarvest(null, null, $set) > 0)); }
    public function findForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec, int $limit, int $offset): array
    {
        $records = array_values(array_filter($this->records, static fn (Record $record): bool => $record->getSetSpecs() !== []
            && ($setSpec === null || in_array($setSpec, $record->getSetSpecs(), true))
            && ($from === null || $record->getDatestamp() >= $from) && ($until === null || $record->getDatestamp() <= $until)));
        return array_slice($records, $offset, $limit);
    }
    public function findHeadersForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec, int $limit, int $offset): array
    {
        return array_map(static fn(Record $record): array => ['oaiIdentifier'=>$record->getOaiIdentifier(),'axiellPriref'=>$record->getAxiellPriref(),'datestamp'=>$record->getDatestamp(),'setSpecs'=>$record->getSetSpecs()], $this->findForHarvest($from,$until,$setSpec,$limit,$offset));
    }
    public function countForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec): int
    {
        return count($this->findForHarvest($from,$until,$setSpec,PHP_INT_MAX,0));
    }
}

$repository = new FixtureRepository();
foreach (range(1, 105) as $id) {
    $priref = (string) (10000000 + $id);
    $repository->records[] = (new Record())->setOaiIdentifier('test:'.$id)->setDatestamp(new DateTimeImmutable('2026-10-16T00:00:00Z'))
        ->setAxiellPriref($priref)
        ->setPublicationSets(['musea_brugge','vkc'])->setOaiXml('<record><priref>'.$priref.'</priref><institution.name><name>Musea Brugge</name></institution.name></record>')
        ->setOaiXmlNative('<record><priref>'.$priref.'</priref><institution.name>Musea Brugge</institution.name></record>');
}
foreach ([['mskObj','vkc'],['muzee','vkc'],[]] as $i=>$sets) {
    $priref = ['310000001','320000002','999'][$i];
    $repository->records[] = (new Record())->setOaiIdentifier('extra:'.$i)->setDatestamp(new DateTimeImmutable('2026-10-16T00:00:00Z'))
        ->setAxiellPriref($priref)
        ->setPublicationSets($sets)->setOaiXml('<record><priref>'.$priref.'</priref><title>Central</title></record>')->setOaiXmlNative('<record><priref>'.$priref.'</priref><title>Native</title></record>');
}
$controller = new OaiPmhController($repository);
function callOai(array $parameters, string $method = 'GET'): DOMXPath
{
    global $controller;
    $response = $controller(Request::create('https://adapter.invalid/oai', $method, $parameters));
    $doc = new DOMDocument();
    check($doc->loadXML($response->getContent(), LIBXML_NONET), 'Invalid OAI XML.');
    $x = new DOMXPath($doc);
    $x->registerNamespace('o','http://www.openarchives.org/OAI/2.0/');
    return $x;
}
$x = callOai(['verb'=>'Identify']);
check($x->evaluate('string(//o:protocolVersion)') === '2.0', 'Identify failed.');
$x = callOai(['verb'=>'ListSets']);
$sets = array_map(static fn (DOMNode $node): string => $node->textContent, iterator_to_array($x->query('//o:set/o:setSpec')));
check($sets === ['mskObj','musea_brugge','muzee','vkc'], 'Expected exactly the four sets.');
$x = callOai(['verb'=>'ListMetadataFormats']);
check($x->evaluate('count(//o:metadataFormat)') === 1.0 && $x->evaluate('string(//o:metadataPrefix)') === 'oai_adlib', 'Expected one metadata format.');

$x = callOai(['verb'=>'ListRecords','metadataPrefix'=>'oai_adlib','set'=>'musea_brugge']);
check($x->evaluate('count(//o:metadata/o:record/o:institution.name/o:name)') === 0.0 && $x->evaluate('count(//o:metadata)') === 100.0, 'Native profile or pagination wrong.');
check($x->evaluate('count(//o:header/o:setSpec)') === 100.0, 'Each item must have exactly one membership.');
check($x->evaluate('string(//o:header/o:identifier)') === 'musea_brugge:10000001', 'Axiell identifier not retained.');
$token = $x->evaluate('string(//o:resumptionToken)');
check($token !== '' && $x->evaluate('string(//o:resumptionToken/@completeListSize)') === '105', 'Wrong total/token.');
$x = callOai(['verb'=>'ListRecords','resumptionToken'=>$token], 'POST');
check($x->evaluate('count(//o:metadata)') === 5.0 && $x->evaluate('count(//o:metadata/o:record/o:institution.name/o:name)') === 0.0, 'Resumption lost the profile.');
check($x->evaluate('string(/o:OAI-PMH/o:request/@resumptionToken)') === $token, 'POST token not echoed.');
$x = callOai(['verb'=>'ListRecords','metadataPrefix'=>'oai_adlib','set'=>'vkc']);
check($x->evaluate('count(//o:metadata/o:record/o:institution.name/o:name)') === 100.0, 'VKC shape changed.');

// The same object has a native and a VKC item, retaining its Axiell priref.
foreach (['mskObj','musea_brugge','muzee','vkc'] as $set) {
    $x = callOai(['verb'=>'ListRecords','metadataPrefix'=>'oai_adlib','set'=>$set]);
    $id = $x->evaluate('string(//o:header/o:identifier)');
    $prefix = $set === 'mskObj' ? 'mskobj' : $set;
    check($id === $prefix.':'.$x->evaluate('string(//o:metadata/o:record/o:priref)'), 'Header and XML identity differ.');
    $listed = $x->query('//o:metadata/*')->item(0)->C14N();
    $single = callOai(['verb'=>'GetRecord','metadataPrefix'=>'oai_adlib','identifier'=>$id]);
    check($single->query('//o:metadata/*')->item(0)->C14N() === $listed, 'GetRecord did not reproduce the listed payload.');
    check($single->evaluate('string(//o:header/o:setSpec)') === $set && $single->evaluate('string(//o:header/o:identifier)') === $id, 'GetRecord changed membership or identity.');
    if (in_array($set, ['mskObj','muzee'], true)) check($single->evaluate('string(//o:metadata/o:record/o:title)') === 'Native', 'Museum profile unavailable.');
    $formats = callOai(['verb'=>'ListMetadataFormats','identifier'=>$id]);
    check($formats->evaluate('count(//o:metadataFormat)') === 1.0, 'Canonical format discovery failed.');
}
$x = callOai(['verb'=>'GetRecord','metadataPrefix'=>'oai_adlib','identifier'=>'vkc:10000001']);
check($x->evaluate('string(//o:metadata/o:record/o:institution.name/o:name)') === 'Musea Brugge', 'VKC GetRecord used the museum profile.');

foreach (['musea_brugge:1','mskobj:10000001','vkc:999','vkc:absent','test:1','test:1::set:musea_brugge'] as $identifier) {
    foreach (['GetRecord','ListMetadataFormats'] as $verb) {
        $parameters = ['verb'=>$verb,'identifier'=>$identifier];
        if ($verb === 'GetRecord') $parameters['metadataPrefix'] = 'oai_adlib';
        $x = callOai($parameters);
        check($x->evaluate('string(//o:error/@code)') === 'idDoesNotExist', 'Unknown identity fell back to another object or set: '.$identifier);
    }
}
foreach (['oai_adlib_mskObj','oai_adlib_musea_brugge','oai_adlib_muzee','oai_adlib_vkc','unknown'] as $prefix) {
    $x = callOai(['verb'=>'ListRecords','metadataPrefix'=>$prefix,'set'=>'vkc']);
    check($x->evaluate('string(//o:error/@code)') === 'cannotDisseminateFormat' && $x->evaluate('count(//o:header)') === 0.0, 'Removed format exposed metadata.');
}
$x = callOai(['verb'=>'ListRecords','metadataPrefix'=>'oai_adlib','set'=>'msk_gent']);
check($x->evaluate('string(//o:error/@code)') === 'noRecordsMatch', 'Removed set alias remained active.');
$x = callOai(['verb'=>'ListRecords','resumptionToken'=>$token,'metadataPrefix'=>'oai_adlib']);
check($x->evaluate('string(//o:error/@code)') === 'badArgument', 'Token allowed filter override.');
$x = callOai(['verb'=>'ListIdentifiers','resumptionToken'=>$token]);
check($x->evaluate('string(//o:error/@code)') === 'badResumptionToken', 'Token allowed verb change.');
// Paging crosses museum/VKC boundaries. Every item occurs once, and both verbs agree.
$verbIds = [];
foreach (['ListRecords','ListIdentifiers'] as $verb) {
    $params = ['verb'=>$verb,'metadataPrefix'=>'oai_adlib','from'=>'2026-10-16','until'=>'2026-10-16'];
    $ids = []; $sizes = [];
    do {
        $x = callOai($params);
        check($x->evaluate('count(//o:error)') === 0.0, 'Unfiltered harvest failed.');
        $headers = $x->query('//o:header'); $sizes[] = $headers->length;
        foreach ($headers as $header) {
            check($x->evaluate('count(o:setSpec)', $header) === 1.0, 'Item belongs to multiple views.');
            $id = $x->evaluate('string(o:identifier)', $header);
            check(!isset($ids[$id]), 'Duplicate item across pages: '.$id);
            $ids[$id] = true;
        }
        $next = $x->evaluate('string(//o:resumptionToken)');
        if ($next !== '') check($x->evaluate('string(//o:resumptionToken/@completeListSize)') === '214', 'Unfiltered total wrong.');
        $params = ['verb'=>$verb,'resumptionToken'=>$next];
    } while ($next !== '');
    check($sizes === [100,100,14] && count($ids) === 214, 'Profile boundary lost records.');
    $verbIds[$verb] = array_keys($ids);
}
check($verbIds['ListRecords'] === $verbIds['ListIdentifiers'], 'Identifier harvest disagrees with record harvest.');
$x = callOai(['verb'=>'ListRecords','metadataPrefix'=>'oai_adlib','from'=>'2026-10-17']);
check($x->evaluate('string(//o:error/@code)') === 'noRecordsMatch', 'Date filtering lost.');
$oldToken = rtrim(strtr(base64_encode(json_encode(['verb'=>'ListRecords','metadataPrefix'=>'oai_adlib','offset'=>100])), '+/', '-_'), '=');
$x = callOai(['verb'=>'ListRecords','resumptionToken'=>$oldToken]);
check($x->evaluate('string(//o:error/@code)') === 'badResumptionToken', 'Pre-migration token accepted.');
$state = json_decode(base64_decode(strtr($token, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
$state['version'] = 2;
$oldToken = rtrim(strtr(base64_encode(json_encode($state)), '+/', '-_'), '=');
$x = callOai(['verb'=>'ListRecords','resumptionToken'=>$oldToken]);
check($x->evaluate('string(//o:error/@code)') === 'badResumptionToken', 'Old suffix-identifier token accepted.');
$repository->records[1]->setAxiellPriref(null);
foreach (['ListRecords','ListIdentifiers'] as $verb) {
    $x = callOai(['verb'=>$verb,'metadataPrefix'=>'oai_adlib','set'=>'musea_brugge']);
    check($x->evaluate('string(//o:error/@code)') === 'cannotDisseminateFormat' && $x->evaluate('count(//o:header)') === 0.0, 'Missing rebuilt identity exposed a partial harvest.');
}
echo "PASS: four set-specific OAI views, stable identifiers, GetRecord consistency, cross-profile paging, POST tokens, date filters and format restrictions\n";

// Exercise publication from parsed source through both mapped views and the OAI controller.
$repository = new FixtureRepository();
$controller = new OaiPmhController($repository);
$parser = new App\Mapping\MuseumPlusExportParser(new App\Mapping\XmlValueExtractor());
$builder = new App\Import\MuseumPlusRecordBuilder(
    new App\Axiell\AxiellRecordMapper(new App\Normalizer\CreatorNormalizer(), new App\Normalizer\DimensionNormalizer(), new App\Normalizer\LabelNormalizer()),
    new App\Axiell\AxiellXmlRenderer(new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'))),
);
$expectedIds = []; $id = 800000;
foreach (['mskObj'=>'Museum voor Schone Kunsten Gent','musea_brugge'=>'Musea Brugge','muzee'=>'Mu.ZEE, Kunstmuseum aan zee'] as $set=>$museum) {
    foreach ([[[], []], [['Extranet'], [$set]], [['VKC datahub'], ['vkc']], [['Extranet','VKC datahub'], [$set,'vkc']]] as [$flags,$expectedSets]) {
        ++$id;
        $flagXml = implode('', array_map(static fn(string $flag): string => '<webpublication>'.$flag.'</webpublication>', $flags));
        $source = '<ObjectList><Object><priref>'.$id.'</priref><MuseumTxt>'.htmlspecialchars('<institution.name>'.$museum.'</institution.name>', ENT_XML1).'</MuseumTxt><WebPublicationTxt>'.htmlspecialchars($flagXml, ENT_XML1).'</WebPublicationTxt></Object></ObjectList>';
        $parsed = $parser->parse($source)[0];
        $row = $builder->build($parsed, '2026-10-19 00:00:00', 'test:', 'vkc');
        $actualSets = array_keys(array_filter(Record::PUBLICATION_COLUMNS, static fn(string $column): bool => (bool) $row[$column]));
        check($actualSets === $expectedSets, 'Parsed flags do not match published sets.');
        check(($row['oai_xml'] !== '') === in_array('vkc', $expectedSets, true), 'VKC XML was generated without its flag.');
        check(($row['oai_xml_native'] !== null) === in_array($set, $expectedSets, true), 'Museum XML was generated without Extranet.');
        check($row['museumplus_xml'] === $parsed->museumplusXml, 'Publication filtering modified source XML.');
        $repository->records[] = (new Record())->setAxiellPriref($row['axiell_priref'])->setOaiIdentifier($row['oai_identifier'])
            ->setDatestamp(new DateTimeImmutable($row['datestamp']))->setPublicationSets($actualSets)
            ->setOaiXml($row['oai_xml'])->setOaiXmlNative($row['oai_xml_native']);
        foreach (AxiellProfile::cases() as $profile) {
            $identifier = $profile->identifier($row['axiell_priref']);
            $allowed = in_array($profile->value, $expectedSets, true);
            if ($allowed) $expectedIds[$profile->value][] = $identifier;
            foreach (['GetRecord','ListMetadataFormats'] as $verb) {
                $params = ['verb'=>$verb,'identifier'=>$identifier];
                if ($verb === 'GetRecord') $params['metadataPrefix'] = 'oai_adlib';
                $x = callOai($params);
                check($x->evaluate('string(//o:error/@code)') === ($allowed ? '' : 'idDoesNotExist'), 'Direct lookup bypassed publication flags: '.$identifier);
            }
        }
    }
}
foreach (['ListRecords','ListIdentifiers'] as $verb) {
    foreach ([null, ...array_keys(Record::PUBLICATION_FIELDS)] as $set) {
        $params = ['verb'=>$verb,'metadataPrefix'=>'oai_adlib'];
        if ($set !== null) $params['set'] = $set;
        $x = callOai($params);
        $actual = array_map(static fn(DOMNode $node): string => $node->textContent, iterator_to_array($x->query('//o:header/o:identifier')));
        $expected = $set === null ? array_merge(...array_values($expectedIds)) : $expectedIds[$set];
        sort($actual); sort($expected);
        check($actual === $expected && $x->evaluate('count(//o:error)') === 0.0, 'Harvest leaked or omitted a publication profile.');
    }
}
foreach ($repository->records as $record) $record->setPublicationSets([])->setOaiXml('')->setOaiXmlNative(null);
foreach (['ListRecords','ListIdentifiers'] as $verb) {
    $x = callOai(['verb'=>$verb,'metadataPrefix'=>'oai_adlib']);
    check($x->evaluate('string(//o:error/@code)') === 'noRecordsMatch', 'Fully withdrawn dataset still exposed records.');
}
$x = callOai(['verb'=>'ListSets']);
check($x->evaluate('count(//o:set)') === 0.0, 'Withdrawn sets still advertised.');
$x = callOai(['verb'=>'GetRecord','metadataPrefix'=>'oai_adlib','identifier'=>'vkc:800004']);
check($x->evaluate('string(//o:error/@code)') === 'idDoesNotExist', 'Withdrawn identifier still exposed metadata.');
echo "PASS: publication flags control XML creation, all harvests and direct lookups; unflagged sources remain private\n";
