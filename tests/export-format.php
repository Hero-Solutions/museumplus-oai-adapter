<?php

declare(strict_types=1);

// php -n tests/export-format.php
// User-supplied XML (location note anonymised) and synthetic fixtures only.
// No kernel, .env, database or HTTP client. URLs in metadata are never fetched.

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Import\MuseumPlusRecordBuilder;
use App\Mapping\InvalidMuseumPlusResponse;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\XmlValueExtractor;
use App\Normalizer\CreatorNormalizer;
use App\Normalizer\DimensionNormalizer;
use App\Normalizer\LabelNormalizer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__).'/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$parser = new MuseumPlusExportParser(new XmlValueExtractor());
$builder = new MuseumPlusRecordBuilder(
    new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()),
    new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'))),
);
$source = file_get_contents(__DIR__.'/fixtures/museumplus-priref.xml');
$parsed = $parser->parse($source);
check(count($parsed) === 1, 'Expected one object in the supplied response.');
$record = $parsed[0];
check($record->museumplusId === '25001583' && $record->values->sourceIdentifier === '25001583', 'priref was not used as the source identifier.');
check($record->values->objectNumber === 'BL-2021-002', 'NumberTxt was not used as the object number.');
check($record->values->invalidFragments === [] && $record->values->repairedFragments === [], 'Valid sample fragments were changed or omitted.');
check(str_contains($record->museumplusXml, '<priref>25001583</priref>') && str_contains($record->museumplusXml, '<NumberTxt>') && !str_contains($record->museumplusXml, '<ID>'), 'Original column names were rewritten in the saved source.');
$row = $builder->build($record, '2026-10-14 12:00:00', 'test:', 'fallback');
check($row['oai_identifier'] === 'test:25001583' && $row['set_spec'] === 'musea_brugge', 'Identifier or museum set was not derived from the revised fields.');
check($row['publish_brugge'] === 0 && $row['publish_vkc'] === 0 && $row['oai_xml'] === '' && $row['oai_xml_native'] === null, 'The unflagged sample was published.');
check($row['museumplus_xml'] === $record->museumplusXml, 'Unpublished source was changed.');
// Exercise field mapping separately: the supplied unflagged source must not produce an OAI item.
$mapped = (new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()))->map($record->values);
$rendered = (new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'))))->renderRecord($mapped);
$document = new DOMDocument();
check($document->loadXML($rendered, LIBXML_NONET), 'Output XML is invalid.');
$xpath = new DOMXPath($document);
foreach ([
    '/record/priref' => '25001583',
    '/record/object_number' => 'BL-2021-002',
    '/record/institution.name/name' => 'Musea Brugge',
    '/record/institution.original_name' => 'Privéverzameling Brugge',
    '/record/Title/title' => 'Heilige Martinus',
    '/record/Title/title.language' => 'nl',
    '/record/Production/creator/name' => 'Meester van de Ursulalegende ?',
    '/record/Production/creator.lref' => '25045787',
    '/record/Production_date/production.date.start' => '1450',
    '/record/Production_date/production.date.end' => '1500',
    '/record/object_category/term' => 'schilderkunst',
    '/record/Object_name/object_name/term' => 'schilderingen',
    '/record/school_style' => 'Vlaamse primitieven',
    '/record/Current_location/current_location/name' => 'extern',
    '/record/Current_location/current_location/op' => 'Depot',
    '/record/Current_location/current_location.context' => 'extern',
    '/record/Current_location/current_location.lref' => '1411322',
    '/record/Current_location/current_location.type' => 'LOCATION',
    '/record/Current_location/current_location.date' => '2026-04-08',
    '/record/Current_location/current_location.notes' => 'Testnotitie',
    '/record/Digital_reference[digital_reference.description="workpid"]/digital_reference' => 'https://www.museabrugge.be/collection/work/id/BL-2021-002',
    '/record/Digital_reference[digital_reference.description="datapid"]/digital_reference' => 'https://www.museabrugge.be/collection/work/data/BL-2021-002',
    '/record/Digital_reference[digital_reference.description="representationpid"]/digital_reference' => 'https://www.museabrugge.be/collection/work/representation/BL-2021-002',
    '/record/Reproduction/reproduction.reference' => 'https://id.erfgoeddata.be/ark:/23947/2025c7e1-1245-32b6-6ce6-e5d1f538b11c',
    '/record/Reproduction/reproduction.reference.lref' => '25020063',
] as $path => $expected) {
    check($xpath->evaluate('string('.$path.')') === $expected, 'Missing or changed output at '.$path);
}
check($xpath->evaluate('count(/record/Digital_reference)') === 3.0, 'PID references were lost.');
check($xpath->evaluate('count(/record/Production/creator.qualifier)') === 0.0, 'A question mark in the creator name must not invent a separate qualifier.');
fwrite(STDOUT, "PASS: supplied priref sample preserves all populated fields, including source links and location details\n");

$fields = [
    'MuseumTxt' => '<institution.name>Museum voor Schone Kunsten Gent</institution.name>',
    'WebPublicationTxt' => '<webpublication>Extranet</webpublication><webpublication>VKC datahub</webpublication>',
    'TitleTxt_nl' => '<Title><title>A</title></Title><Title><title>B</title><title.type>oude titel</title.type></Title>',
    'MaterialTxt' => '<Material><material>papier</material></Material><Material><material>krijt</material><material.notes>wit</material.notes></Material>',
    'DescriptionTxt' => '<Description><description>Alpha & Beta</description></Description>',
    'ProductionDateTxt' => '<Production_date><production.date.start>1900</production.date.start></Production_date><Production_date><production.date.start>1950</production.date.start><production.date.end>1960</production.date.end></Production_date>',
    'CreatorTxt' => '<Production><creator>Maker A</creator></Production><Production><creator>Maker B</creator><creator.lref>502</creator.lref></Production>',
    'CurrentLocationTxt' => '<Current_location><current_location>A</current_location></Current_location><Current_location><current_location>B</current_location><op>Depot</op><current_location.lref>602</current_location.lref><current_location.context>Context B</current_location.context><current_location.type>LOCATION</current_location.type><current_location.date>2026-04-08</current_location.date><current_location.notes>Notitie B</current_location.notes></Current_location><Current_location><current_location>C</current_location><current_location.type>OTHER</current_location.type></Current_location>',
    'ReproductionTxt' => '<Reproduction><reproduction.reference>https://example.invalid/image-a</reproduction.reference></Reproduction><Reproduction><reproduction.reference>https://example.invalid/image-b</reproduction.reference><reproduction.reference.lref>702</reproduction.reference.lref></Reproduction>',
];
$xml = '<ObjectList><Object><priref>test-new</priref>';
foreach ($fields as $field => $fragment) {
    $xml .= '<'.$field.'>'.htmlspecialchars($fragment, ENT_QUOTES | ENT_XML1, 'UTF-8').'</'.$field.'>';
}
$record = $parser->parse($xml.'</Object></ObjectList>')[0];
check(count($record->values->repairedFragments) === 1 && $record->values->repairedFragments[0]['field'] === 'DescriptionTxt', 'Diagnostics must use the original export field name.');
$row = $builder->build($record, '2026-10-14 12:00:00', 'test:', 'fallback');
check($row['set_spec'] === 'mskObj', 'Revised museum column did not preserve MSK routing.');
check($document->loadXML($row['oai_xml'], LIBXML_NONET), 'Extended sample produced invalid XML.');
$xpath = new DOMXPath($document);
check($xpath->evaluate('count(/record/Title[title="A"]/title.type)') === 0.0 && $xpath->evaluate('string(/record/Title[title="B"]/title.type)') === 'oude titel', 'Title repetitions were merged.');
check($xpath->evaluate('count(/record/Material[material/term="papier"]/material.notes)') === 0.0 && $xpath->evaluate('string(/record/Material[material/term="krijt"]/material.notes)') === 'wit', 'Material repetitions were merged.');
check($xpath->evaluate('count(/record/Production_date[production.date.start="1900"]/production.date.end)') === 0.0 && $xpath->evaluate('string(/record/Production_date[production.date.start="1950"]/production.date.end)') === '1960', 'Date group normalisation shifted an optional end date.');
check($xpath->evaluate('string(/record/Description/description)') === 'Alpha & Beta', 'Repaired text was not retained.');
check($xpath->evaluate('count(/record/Production[creator/name="Maker A"]/creator.lref)') === 0.0 && $xpath->evaluate('string(/record/Production[creator/name="Maker B"]/creator.lref)') === '502', 'A creator link moved to another creator.');
check($xpath->evaluate('count(/record/Reproduction[reproduction.reference="https://example.invalid/image-a"]/reproduction.reference.lref)') === 0.0 && $xpath->evaluate('string(/record/Reproduction[reproduction.reference="https://example.invalid/image-b"]/reproduction.reference.lref)') === '702', 'A reproduction link moved to another reproduction.');
foreach (['lref' => '602', 'context' => 'Context B', 'type' => 'LOCATION', 'date' => '2026-04-08', 'notes' => 'Notitie B'] as $field => $expected) {
    check($xpath->evaluate('string(/record/Current_location[current_location/name="B"]/current_location.'.$field.')') === $expected, 'Missing location detail '.$field);
    check($xpath->evaluate('count(/record/Current_location[current_location/name="A"]/current_location.'.$field.')') === 0.0, 'Location A inherited '.$field);
    if ($field !== 'type') {
        check($xpath->evaluate('count(/record/Current_location[current_location/name="C"]/current_location.'.$field.')') === 0.0, 'Location C inherited '.$field);
    }
}
check($xpath->evaluate('string(/record/Current_location[current_location/name="C"]/current_location.type)') === 'OTHER', 'Location types were merged.');
check($xpath->evaluate('string(/record/Current_location[current_location/name="B"]/current_location/op)') === 'Depot' && $xpath->evaluate('count(/record/Current_location[current_location/name!="B"]/current_location/op)') === 0.0, 'A public location moved to another group.');
fwrite(STDOUT, "PASS: revised fields preserve repeated groups, optional links/location details/dates, repairs and museum routing\n");

$legacyNames = [
    'WebPublicationTxt' => 'WebPublicationTxt',
    'MuseumTxt' => 'Naam_bewaarinstelling', 'TitleTxt_nl' => 'Titel_nl',
    'MaterialTxt' => 'Term_materiaal', 'DescriptionTxt' => 'Beschrijving',
    'ProductionDateTxt' => 'Productiedatum', 'CreatorTxt' => 'Naam_vervaardiger',
    'CurrentLocationTxt' => 'Huidige_standplaats', 'ReproductionTxt' => 'Reproductie',
];
$legacyXml = '<ObjectList><Object><ID>test-new</ID>';
foreach ($fields as $field => $fragment) {
    if ($field === 'ProductionDateTxt') {
        $fragment = str_replace(['<Production_date>', '</Production_date>'], ['<Production>', '</Production>'], $fragment);
    }
    $name = $legacyNames[$field];
    $legacyXml .= '<'.$name.'>'.htmlspecialchars($fragment, ENT_QUOTES | ENT_XML1, 'UTF-8').'</'.$name.'>';
}
$legacyRecord = $parser->parse($legacyXml.'</Object></ObjectList>')[0];
$legacyRow = $builder->build($legacyRecord, '2026-10-14 12:00:00', 'test:', 'fallback');
check($legacyRow['oai_xml'] === $row['oai_xml'], 'Equivalent old and new exports produce different output.');
fwrite(STDOUT, "PASS: legacy fields preserve the same grouped links and location details\n");

foreach ([
    '<Object><UnrecognisedId>123</UnrecognisedId><CreatorTxt>Test</CreatorTxt></Object>' => 'without an ID or priref',
    '<Object><ID/><priref> </priref></Object>' => 'without an ID or priref',
    '<Object><ID>123</ID><priref>456</priref></Object>' => 'conflicting ID and priref',
] as $object => $message) {
    $caught = null;
    try {
        $parser->parse('<ObjectList>'.$object.'</ObjectList>');
    } catch (RuntimeException $error) {
        $caught = $error;
    }
    check($caught !== null && str_contains($caught->getMessage(), $message), 'Unsupported identity format was accepted.');
    check(!$caught instanceof InvalidMuseumPlusResponse, 'A format error must not enter the automatic invalid-XML skip path.');
}
check($parser->parse('<ObjectList/>') === [], 'A genuine empty export must remain valid.');
foreach (['<ID>123</ID>', '<ID/><priref>123</priref>', '<ID>123</ID><priref>123</priref>'] as $identity) {
    check($parser->parse('<ObjectList><Object>'.$identity.'</Object></ObjectList>')[0]->museumplusId === '123', 'Legacy, fallback or matching dual identifiers failed.');
}
check((new InvalidMuseumPlusResponse('broken', '<ObjectList><Object><priref>123</priref><TitleTxt_nl>'))->museumplusId() === '123', 'Truncated new exports cannot be isolated by priref.');
foreach (['<ObjectList><Object><priref>123</ID>', '<html><ObjectList><Object><priref>123</priref>', '<ObjectList><Object><priref>not-numeric</priref>'] as $invalid) {
    check((new InvalidMuseumPlusResponse('broken', $invalid))->museumplusId() === null, 'An unreliable identity was accepted for automatic skipping.');
}
fwrite(STDOUT, "PASS: unknown/conflicting identities stop; old exports and strict priref error recognition remain supported\n");
