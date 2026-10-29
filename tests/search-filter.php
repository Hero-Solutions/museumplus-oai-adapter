<?php

declare(strict_types=1);

// Run with: php -n tests/search-filter.php
// Parse synthetic configuration and XML only; no application bootstrap or clients.

use App\Import\MuseumPlusSearch;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_ENV = $_SERVER = [];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$settings = (new Dotenv())->parse(<<<'CONFIG'
MUSEUMPLUS_WEBPUBLICATION_IDS='
  202

  101, 202
'
CONFIG);
$ids = MuseumPlusSearch::parsePublicationIds($settings['MUSEUMPLUS_WEBPUBLICATION_IDS']);
check($ids === ['101', '202'], 'Multiline values, commas or duplicate IDs were not handled.');
check(MuseumPlusSearch::parsePublicationIds("\r\n ,202,\r\n101, ") === $ids, 'Windows newlines or trailing commas changed the selection.');

foreach (['', " \n\t", ', ,', '0', '-1', '0101', '101.5', '1e3', '101;202', '101,Extranet', 'org_1_TestMuseum', '101,"/><or>'] as $invalid) {
    $error = null;
    try {
        MuseumPlusSearch::parsePublicationIds($invalid);
    } catch (RuntimeException $exception) {
        $error = $exception;
    }
    check($error !== null, 'Empty, non-numeric or malformed selection accepted: '.$invalid);
}

foreach ([['101'], $ids, ['202', '101', '202']] as $selection) {
    $document = new DOMDocument();
    check($document->loadXML(MuseumPlusSearch::xml($selection, 1000, 42), LIBXML_NONET), 'Search XML is invalid.');
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://www.zetcom.com/ria/ws/module/search');
    $expert = '/s:application/s:modules/s:module[@name="Object"]/s:search[@limit="1000" and @offset="42"]/s:expert';
    $expected = array_values(array_unique($selection));
    sort($expected, SORT_STRING);
    $criteria = $expert.(count($expected) === 1 ? '' : '/s:or').'/s:equalsField';
    check($xpath->evaluate('count('.$expert.'/s:or)') === (count($expected) === 1 ? 0.0 : 1.0), 'Publication flags must be OR alternatives.');
    check($xpath->evaluate('count(//s:and | //s:equalsField[@fieldPath!="ObjWebPublicationGrp.WebPublicationVoc"])') === 0.0, 'An organisation/category condition restricts the publication selection.');
    $nodes = $xpath->query($criteria);
    check($nodes->length === count($expected), 'Missing or duplicate publication ID.');
    foreach ($nodes as $index => $node) {
        check($node->getAttribute('operand') === $expected[$index], 'Wrong publication ID.');
    }
}
foreach ([[[], 100, 0], [['Extranet'], 100, 0], [['101'], 0, 0], [['101'], 100, -1]] as [$selection, $limit, $offset]) {
    $error = null;
    try {
        MuseumPlusSearch::xml($selection, $limit, $offset);
    } catch (RuntimeException $exception) {
        $error = $exception;
    }
    check($error !== null, 'Unsafe selection or pagination accepted by the XML builder.');
}
fwrite(STDOUT, "PASS: required numeric publication IDs, multiline lists, deduplication, pagination and OR-only webpublication filter\n");
