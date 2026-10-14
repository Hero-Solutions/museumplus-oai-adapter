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
MUSEUMPLUS_ORG_UNITS='
  org_2_OtherMuseum

  org_1_TestMuseum, org_2_OtherMuseum
'
CONFIG);
$orgUnits = MuseumPlusSearch::parseOrgUnits($settings['MUSEUMPLUS_ORG_UNITS']);
check($orgUnits === ['org_1_TestMuseum', 'org_2_OtherMuseum'], 'Multiline values, commas or duplicate codes were not handled.');
check(MuseumPlusSearch::parseOrgUnits("\r\n ,org_2_OtherMuseum,\r\norg_1_TestMuseum, ") === $orgUnits, 'Windows newlines or trailing commas changed the selection.');
check(MuseumPlusSearch::parseOrgUnits('') === [] && MuseumPlusSearch::parseOrgUnits(" \n\t") === [], 'An empty setting must preserve the original category-only filter.');

$error = null;
try {
    MuseumPlusSearch::parseOrgUnits(', ,');
} catch (RuntimeException $exception) {
    $error = $exception;
}
check($error !== null, 'A list containing only commas must not silently remove the filter.');

foreach ([[], ['org_1_TestMuseum'], $orgUnits, ['org_1_Test&"Museum']] as $selection) {
    $document = new DOMDocument();
    check($document->loadXML(MuseumPlusSearch::xml('test.field', 'one & "two"', 1000, 42, $selection), LIBXML_NONET), 'Search XML is invalid.');
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://www.zetcom.com/ria/ws/module/search');
    $expert = '/s:application/s:modules/s:module[@name="Object"]/s:search[@limit="1000" and @offset="42"]/s:expert';
    $category = $expert.($selection === [] ? '' : '/s:and').'/s:equalsField';
    check($xpath->evaluate('count('.$category.')') === 1.0, 'Category must occur exactly once outside the organisation OR.');
    check($xpath->evaluate('string('.$category.'/@operand)') === 'one & "two"', 'XML escaping changed the category value.');
    check($xpath->evaluate('count('.$expert.'/s:and/s:or)') === ($selection === [] ? 0.0 : 1.0), 'Missing OR group or unexpected empty OR.');
    $nodes = $xpath->query($expert.'/s:and/s:or/s:equalsField[@fieldPath="__orgUnit"]');
    check($nodes->length === count($selection), 'Organisation filters must be alternatives in the same OR group.');
    foreach ($nodes as $index => $node) {
        check($node->getAttribute('operand') === $selection[$index], 'XML escaping changed an organisation code.');
    }
}
fwrite(STDOUT, "PASS: multiline configuration, empty selection, deduplication, XML escaping and AND/OR structure\n");

// Verify that the complete copyable example is valid Symfony dotenv syntax.
$readme = file_get_contents(dirname(__DIR__).'/README.md');
check(preg_match("/```dotenv\s+(MUSEUMPLUS_ORG_UNITS='.*?')\s+```/s", $readme, $match) === 1, 'Missing complete configuration example.');
$settings = (new Dotenv())->parse($match[1]);
$orgUnits = MuseumPlusSearch::parseOrgUnits($settings['MUSEUMPLUS_ORG_UNITS']);
check(count($orgUnits) === 30, 'The VKC example must contain all 30 distinct organisation codes.');
foreach (['org_1_' => 15, 'org_31_' => 4, 'org_32_' => 11] as $prefix => $count) {
    check(count(array_filter($orgUnits, static fn (string $code): bool => str_starts_with($code, $prefix))) === $count, 'Incomplete museum group in the example: '.$prefix);
}
fwrite(STDOUT, "PASS: full VKC example parses as a multiline environment variable with 30 codes\n");
