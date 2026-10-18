<?php

declare(strict_types=1);

// php -n tests/xml-fragments.php
// Synthetic XML only; no kernel, .env, database or HTTP client.

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
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

$extractor = new XmlValueExtractor();
$cases = [
    ['<description>Alpha & Beta, R&D</description>', 'description', ['Alpha & Beta, R&D'], true],
    ['<description>Alpha &amp; Beta &lt;label&gt; &#38; &#x26;</description>', 'description', ['Alpha & Beta <label> & &'], false],
    ['<description>Alpha &amp; Beta & Gamma</description>', 'description', ['Alpha & Beta & Gamma'], true],
    ['<description><![CDATA[<label> & ampersand]]> & end</description>', 'description', ['<label> & ampersand & end'], true],
    ['<description><![CDATA[<object_name><label words></object_name>]]> & end</description>', 'description', ['<object_name><label words></object_name> & end'], true],
    ['<description>Alpha<!-- & comment --> & Beta</description>', 'description', ['Alpha & Beta'], true],
    ['<description note="&amp;">Alpha & Beta</description>', 'description', ['Alpha & Beta'], true],
    ['<Object_category><object_category>koperplaat</object_category></Object_category><Object_category><object_category><koper en koperlegering></object_category></Object_category>', 'Object_category/object_category', ['koperplaat', '<koper en koperlegering>'], true],
    ['<Object_name><object_name><weeginstrumenten naar vorm></object_name></Object_name>', 'Object_name/object_name', ['<weeginstrumenten naar vorm>'], true],
    ['<Material><material><koper en koperlegering></material></Material>', 'Material/material', ['<koper en koperlegering>'], true],
    ['<production.notes>Hoogte < 10 en > 5</production.notes>', 'production.notes', ['Hoogte < 10 en > 5'], true],
    ['<production.notes><![CDATA[< 10]]><em>tekst</em></production.notes>', 'production.notes', ['< 10tekst'], false],
    ['<production.notes><![CDATA[< 10]]><!-- < 20 --> & tekst</production.notes>', 'production.notes', ['< 10 & tekst'], true],
    ['<title>Set <<Pak-Me-Mee>></title>', 'title', ['Set <<Pak-Me-Mee>>'], true],
    ["<title.translation>Le singe du <Grand'Gard></title.translation>", 'title.translation', ["Le singe du <Grand'Gard>"], true],
    ['<creator.history>https://example.invalid/?one=x&two=y; geraadpleegd</creator.history>', 'creator.history', ['https://example.invalid/?one=x&two=y; geraadpleegd'], true],
    ['<production.period.notes>< 1880</production.period.notes>', 'production.period.notes', ['< 1880'], true],
    ['<inscription.description>--<>--</inscription.description>', 'inscription.description', ['--<>--'], true],
    ['<object_name>&lt;weeginstrumenten naar vorm&gt;</object_name>', 'object_name', ['<weeginstrumenten naar vorm>'], false],
    ['<description><em>Alpha</em> & Beta</description>', 'description', ['Alpha & Beta'], true],
];
foreach ($cases as [$xml, $path, $expected, $repaired]) {
    $result = $extractor->extractFromFragment($xml);
    check($result['error'] === null, 'Unexpected parse failure: '.($result['error'] ?? ''));
    check(($result['values'][$path] ?? []) === $expected, 'Text changed in '.$xml);
    check(($result['repair'] !== null) === $repaired, 'Incorrect repair reporting.');
}
fwrite(STDOUT, "PASS: bare ampersands and literal vocabulary labels preserved; valid entities, markup, CDATA and comments unchanged\n");

foreach ([
    '<description>truncated & text',
    '<description>Alpha & Beta</wrong>',
    '<object_name><broken></object_name>',
    '<production.notes><broken></production.notes>',
    '<production.notes><em note="< 10">tekst</em> & tekst</production.notes>',
    '<object_name><broken attribute="x"></object_name>',
    '<object_name><broken attribute/></object_name>',
    '<object_name><broken attribute=value></object_name>',
    '<description>&unknown;</description>',
    '<description>&onbekénd;</description>',
    '<description>&#xZZ;</description>',
    '<description>&;</description>',
    '<object_name><label &unknown; words></object_name>',
    '<description>&#0;</description>',
    '<description note="&">Alpha</description>',
    '<description note="a > & b">Alpha</description>',
] as $xml) {
    $result = $extractor->extractFromFragment($xml);
    check($result['error'] !== null && $result['values'] === [] && $result['repair'] === null, 'Invalid structure was accepted: '.$xml);
}
fwrite(STDOUT, "PASS: truncated XML, mismatched tags, malformed attributes and unknown/invalid entities remain errors\n");

$parser = new MuseumPlusExportParser($extractor);
$mapper = new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer());
$renderer = new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates')));
$encode = static fn (string $xml): string => htmlspecialchars($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
$source = '<Object><ID>test-1</ID>'
    .'<Beschrijving>'.$encode('<Description><description>Alpha & Beta</description></Description>').'</Beschrijving>'
    .'<Term_objectcategorie>'.$encode('<Object_category><object_category><weeginstrumenten naar vorm></object_category></Object_category>').'</Term_objectcategorie>'
    .'<Term_objectnaam>'.$encode('<Object_name><object_name><weeginstrumenten naar vorm></object_name></Object_name>').'</Term_objectnaam>'
    .'<Periode>'.$encode('<Production_period><production.period>19de eeuw</production.period></Production_period>').'</Periode>'
    .'</Object>';
$record = $parser->parse('<ObjectList>'.$source.'</ObjectList>')[0];
check($record->values->invalidFragments === [] && count($record->values->repairedFragments) === 3, 'Repair metadata was lost.');
check(str_contains($record->museumplusXml, '&lt;weeginstrumenten naar vorm&gt;'), 'Original raw source was rewritten.');
$document = new DOMDocument();
check($document->loadXML($renderer->renderRecord($mapper->map($record->values))), 'Rendered XML is invalid.');
$xpath = new DOMXPath($document);
check($xpath->evaluate('string(/record/Description/description)') === 'Alpha & Beta', 'Description was lost.');
check($xpath->evaluate('string(/record/object_category/term)') === '<weeginstrumenten naar vorm>', 'Wrapped object category was lost.');
check($xpath->evaluate('string(/record/Object_name/object_name/term)') === '<weeginstrumenten naar vorm>', 'Object name was lost.');
check($xpath->evaluate('string(/record/production.period/term)') === '19de eeuw', 'Wrapped period was lost.');

$legacy = '<ObjectList><Object><ID>legacy</ID>'
    .'<Term_objectcategorie>'.$encode('<object_category>schilderijen</object_category>').'</Term_objectcategorie>'
    .'<Periode>'.$encode('<production.period>20ste eeuw</production.period>').'</Periode></Object></ObjectList>';
$values = $parser->parse($legacy)[0]->values;
check($document->loadXML($renderer->renderRecord($mapper->map($values))), 'Legacy output is invalid.');
$xpath = new DOMXPath($document);
check($xpath->evaluate('string(/record/object_category/term)') === 'schilderijen', 'Legacy category mapping broke.');
check($xpath->evaluate('string(/record/production.period/term)') === '20ste eeuw', 'Legacy period mapping broke.');

$plain = $parser->parse('<ObjectList><Object><priref>plain</priref><physical_description>rand met &lt;&gt; CUIVRE</physical_description></Object></ObjectList>')[0];
check($plain->values->invalidFragments === [] && $plain->values->valuesByPath['Physical_description_NL'] === ['rand met <> CUIVRE'], 'Plain physical description treated as markup.');

$rejected = false;
try {
    $parser->parse('<ObjectList><Object><ID>test</ID><Beschrijving>broken & source');
} catch (InvalidMuseumPlusResponse) {
    $rejected = true;
}
check($rejected, 'Invalid outer export was repaired.');
fwrite(STDOUT, "PASS: full parser-to-renderer round trip, repair reporting, original source preservation, both mapping formats and strict outer XML\n");
