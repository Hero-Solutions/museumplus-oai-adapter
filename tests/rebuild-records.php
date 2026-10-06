<?php

declare(strict_types=1);

// php -n tests/rebuild-records.php
// Real parser, mapper and Twig; an in-memory database double. No kernel or .env.

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Command\RebuildMuseumPlusRecordsCommand;
use App\Import\MuseumPlusRecordBuilder;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\XmlValueExtractor;
use App\Normalizer\CreatorNormalizer;
use App\Normalizer\DimensionNormalizer;
use App\Normalizer\LabelNormalizer;
use App\Tests\Support\MemoryImportConnection;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/MemoryImportConnection.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sourceRow(int $id, string $museum = 'Musea Brugge'): array
{
    $fragment = static fn (string $xml): string => htmlspecialchars($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return [
        'id' => $id,
        'museumplus_id' => (string) $id,
        'museumplus_xml' => '<Object><ID>'.$id.'</ID>'
            .'<Naam_bewaarinstelling>'.$fragment('<institution.name>'.$museum.'</institution.name>').'</Naam_bewaarinstelling>'
            .'<Objectnummer>'.$fragment('<object_number>INV-'.$id.'</object_number>').'</Objectnummer>'
            .'<Titel_nl>'.$fragment('<Title><title>Source title '.$id.'</title></Title>').'</Titel_nl></Object>',
        // Deliberately stale derived values: none may be reused.
        'oai_identifier' => 'stale:'.$id,
        'set_spec' => 'old-set',
        'oai_xml' => '<old-output/>',
        'object_number' => 'old-number',
        'datestamp' => '2000-01-01 00:00:00',
        'created_at' => '2000-01-01 00:00:00',
    ];
}

function database(array $rows): MemoryImportConnection
{
    $db = new MemoryImportConnection();
    $db->tables['records_import'] = array_column($rows, null, 'id');
    $db->tables['museumplus_import_state'][1] = ['status' => 'fetching', 'next_offset' => 659755, 'stored' => 659262];
    $db->tables['museumplus_import_errors'][1] = ['error_message' => 'Saved historical error'];

    return $db;
}

function runRebuild(MemoryImportConnection $db, array $options = [], ?string $expectedError = null): string
{
    $command = new RebuildMuseumPlusRecordsCommand(
        new MuseumPlusExportParser(new XmlValueExtractor()),
        new MuseumPlusRecordBuilder(
            new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()),
            new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'))),
        ),
        $db,
        'current:',
        'fallback',
    );
    $tester = new CommandTester($command);
    $caught = null;
    try {
        check($tester->execute($options, ['interactive' => false]) === 0, 'Command failed.');
    } catch (RuntimeException $error) {
        $caught = $error;
    }
    check($expectedError === null ? $caught === null : ($caught !== null && str_contains($caught->getMessage(), $expectedError)), 'Unexpected result: '.($caught?->getMessage() ?? 'success'));

    return $tester->getDisplay();
}

$db = database([
    sourceRow(1), sourceRow(2, 'Mu.ZEE, Kunstmuseum aan zee'),
    sourceRow(3, 'Museum voor Schone Kunsten Gent'), sourceRow(4, 'Musea Brugge - Groeningemuseum'),
    sourceRow(5, 'Een ander museum'),
]);
$saved = $db->tables;
runRebuild($db);
check(array_keys($db->tables['records']) === ['current:1', 'current:2', 'current:3', 'current:4'], 'Wrong selection or stale identifiers.');
check(array_column($db->tables['records'], 'set_spec') === ['musea_brugge', 'muzee', 'msk_gent', 'musea_brugge'], 'Sets were not rebuilt.');
foreach ($db->tables['records'] as $row) {
    $id = (int) $row['museumplus_id'];
    $xml = new DOMDocument();
    check($xml->loadXML($row['oai_xml']), 'Rebuilt XML is malformed.');
    check($xml->getElementsByTagName('title')->item(0)?->textContent === 'Source title '.$id, 'Old XML reused or current mapper not used.');
    check($row['object_number'] === 'INV-'.$id && $row['datestamp'] !== '2000-01-01 00:00:00', 'Derived fields were not rebuilt.');
    check($row['museumplus_xml'] === $saved['records_import'][$id]['museumplus_xml'], 'Source XML was modified.');
}
foreach (['records_import', 'museumplus_import_state', 'museumplus_import_errors'] as $table) {
    check($db->tables[$table] === $saved[$table], 'Changed preserved table '.$table);
}
check(!$db->locked, 'Lock was not released.');
fwrite(STDOUT, "PASS: fresh XML, identifiers, sets, timestamps and object numbers; source/checkpoint/errors preserved\n");

$published = $db->tables;
runRebuild($db, [], 'records is not empty');
check($db->tables === $published, 'Refusal changed records.');
$db->tables['records_import'][1]['museumplus_xml'] = str_replace('Source title 1', 'Updated source title', $db->tables['records_import'][1]['museumplus_xml']);
$db->tables['records']['old-only'] = ['oai_identifier' => 'old-only'];
runRebuild($db, ['--replace' => true]);
check(count($db->tables['records']) === 4 && str_contains($db->tables['records']['current:1']['oai_xml'], 'Updated source title'), 'Replace did not start clean.');
fwrite(STDOUT, "PASS: nonempty-table guard and explicit clean replacement\n");

foreach (['malformed', 'mismatch', 'insert', 'empty'] as $failure) {
    $rows = [sourceRow(1), sourceRow(2)];
    if ($failure === 'malformed') {
        $rows[1]['museumplus_xml'] = str_replace('</Object>', '<Broken></Object>', $rows[1]['museumplus_xml']);
    } elseif ($failure === 'mismatch') {
        $rows[1]['museumplus_id'] = '999';
    } elseif ($failure === 'empty') {
        $rows = [sourceRow(1, 'Een ander museum')];
    }
    $db = database($rows);
    $db->tables['records']['old'] = ['oai_identifier' => 'old', 'oai_xml' => '<old/>'];
    if ($failure === 'insert') {
        $db->failInsertId = '2';
    }
    $saved = $db->tables;
    runRebuild($db, ['--replace' => true], $failure === 'empty' ? 'No matching museum records' : 'Failed rebuilding MuseumPlus');
    check($db->tables === $saved && !$db->locked, 'Failure did not restore all data: '.$failure);
}
fwrite(STDOUT, "PASS: full rollback on malformed XML, wrong ID, insert failure or empty selection\n");

$db = database([sourceRow(1)]);
$db->locked = true;
$saved = $db->tables;
runRebuild($db, [], 'already running');
check($db->tables === $saved && $db->locked, 'Touched an active import or released its lock.');
$db = database(array_map(sourceRow(...), range(1, 205)));
runRebuild($db);
check(count($db->tables['records']) === 205 && isset($db->tables['records']['current:205']), 'Paging lost records.');
$row = sourceRow(1);
$row['museumplus_xml'] = str_replace('</Object>', '<Broken>&lt;unclosed&gt;</Broken></Object>', $row['museumplus_xml']);
$db = database([$row]);
$output = runRebuild($db);
check(count($db->tables['records']) === 1 && str_contains($output, 'field Broken') && str_contains($output, '1 invalid field fragments'), 'Invalid fragments were silently ignored.');
fwrite(STDOUT, "PASS: active-import lock, multiple batches and visible invalid-fragment warnings\n");

$row = sourceRow(1);
$row['museumplus_xml'] = str_replace('Source title 1', 'Alpha &amp; Beta', $row['museumplus_xml']);
$db = database([$row]);
$saved = $db->tables['records_import'];
$output = runRebuild($db);
check(str_contains($db->tables['records']['current:1']['oai_xml'], 'Alpha &amp; Beta'), 'Repaired title was lost.');
check(str_contains($output, 'field Titel_nl: repaired text escaping') && str_contains($output, '1 field fragments had text escaping repaired'), 'Repair was not reported.');
check(!str_contains($output, 'invalid field fragments') && $db->tables['records_import'] === $saved, 'Repair was treated as a skipped field or source data was changed.');
fwrite(STDOUT, "PASS: repaired text is retained, reported separately and never written back to records_import\n");
