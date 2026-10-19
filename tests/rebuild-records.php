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
require __DIR__.'/Support/TemporaryRawArchive.php';
$testArchive = App\Tests\Support\temporaryRawArchive();

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sourceTablesUnchanged(MemoryImportConnection $db, array $before): bool
{
    foreach (['records', 'records_import', 'museumplus_import_state', 'museumplus_import_errors'] as $table) {
        if ($db->tables[$table] !== $before[$table]) return false;
    }

    return true;
}

function sourceRow(int $id, string $museum = 'Musea Brugge', array $flags = ['Extranet','VKC datahub']): array
{
    $fragment = static fn (string $xml): string => htmlspecialchars($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return [
        'id' => $id,
        'museumplus_id' => (string) $id,
        'museumplus_xml' => '<Object><ID>'.$id.'</ID>'
            .'<Naam_bewaarinstelling>'.$fragment('<institution.name>'.$museum.'</institution.name>').'</Naam_bewaarinstelling>'
            .'<Objectnummer>'.$fragment('<object_number>INV-'.$id.'</object_number>').'</Objectnummer>'
            .'<WebPublicationTxt>'.$fragment(implode('', array_map(static fn(string $flag): string => '<webpublication>'.$flag.'</webpublication>', $flags))).'</WebPublicationTxt>'
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

function runRebuild(MemoryImportConnection $db, array $options = [], ?string $expectedError = null, bool $useDefaultSource = false): string
{
    global $testArchive;
    $command = new RebuildMuseumPlusRecordsCommand(
        new MuseumPlusExportParser(new XmlValueExtractor()),
        new MuseumPlusRecordBuilder(
            new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()),
            new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'))),
        ),
        $db,
        'current:',
        'fallback',
        $testArchive,
    );
    $tester = new CommandTester($command);
    $caught = null;
    try {
        check($tester->execute($options + ($useDefaultSource ? [] : ['--source-table'=>'records_import']), ['interactive' => false]) === 0, 'Command failed.');
    } catch (RuntimeException $error) {
        $caught = $error;
    }
    check($expectedError === null ? $caught === null : ($caught !== null && str_contains($caught->getMessage(), $expectedError)), 'Unexpected result: '.($caught?->getMessage() ?? 'success'));

    return $tester->getDisplay();
}

$db = database([
    sourceRow(1), sourceRow(2, 'Mu.ZEE, Kunstmuseum aan zee'),
    sourceRow(3, 'Museum voor Schone Kunsten Gent'), sourceRow(4, 'Privéverzameling Brugge'),
    sourceRow(5, 'Een ander museum'),
]);
$saved = $db->tables;
runRebuild($db);
check(array_keys($db->tables['records']) === ['current:1', 'current:2', 'current:3', 'current:4', 'current:5'], 'Wrong selection or stale identifiers.');
check(array_column($db->tables['records'], 'set_spec') === ['musea_brugge', 'muzee', 'mskObj', 'musea_brugge', 'fallback'], 'Sets were not rebuilt.');
check($db->tables['records']['current:1']['publish_brugge'] === 1 && $db->tables['records']['current:3']['publish_msk'] === 1 && $db->tables['records']['current:3']['publish_vkc'] === 1 && $db->tables['records']['current:5']['publish_vkc'] === 0, 'Museum pairing or unknown-institution exclusion failed.');
foreach ($db->tables['records'] as $row) {
    $id = (int) $row['museumplus_id'];
    if ($id === 5) {
        check($row['oai_xml'] === '' && $row['oai_xml_native'] === null && $row['museumplus_xml'] === $saved['records_import'][$id]['museumplus_xml'], 'Unknown museum received mapped XML or lost its source.');
        continue;
    }
    $xml = new DOMDocument();
    check($xml->loadXML($row['oai_xml']), 'Rebuilt XML is malformed.');
    check($xml->getElementsByTagName('title')->item(0)?->textContent === 'Source title '.$id, 'Old XML reused or current mapper not used.');
    check($row['axiell_priref'] === (string) $id && $xml->getElementsByTagName('priref')->item(0)?->textContent === $row['axiell_priref'], 'Indexed identity and XML differ.');
    check($row['object_number'] === 'INV-'.$id && $row['datestamp'] !== '2000-01-01 00:00:00', 'Derived fields were not rebuilt.');
    check($row['museumplus_xml'] === $saved['records_import'][$id]['museumplus_xml'], 'Source XML was modified.');
}
foreach (['records_import', 'museumplus_import_state', 'museumplus_import_errors'] as $table) {
    check($db->tables[$table] === $saved[$table], 'Changed preserved table '.$table);
}
check(!$db->locked && $db->renames === 1, 'Lock was not released or publication was not a single swap.');
check(in_array('CREATE TABLE records_rebuild LIKE records', $db->statements, true), 'Rebuild did not copy the current schema and indexes.');
check(!isset($db->tables['records_rebuild']) && !isset($db->tables['records_rebuild_old']), 'Successful rebuild left scratch tables.');
fwrite(STDOUT, "PASS: fresh XML, identifiers, sets, timestamps and object numbers; source/checkpoint/errors preserved\n");

$published = $db->tables;
runRebuild($db, [], 'records is not empty');
check($db->tables === $published, 'Refusal changed records.');
$db->tables['records_import'][1]['museumplus_xml'] = str_replace('Source title 1', 'Updated source title', $db->tables['records_import'][1]['museumplus_xml']);
$db->tables['records']['old-only'] = ['oai_identifier' => 'old-only'];
runRebuild($db, ['--replace' => true]);
check(count($db->tables['records']) === 5 && str_contains($db->tables['records']['current:1']['oai_xml'], 'Updated source title'), 'Replace did not start clean.');
fwrite(STDOUT, "PASS: nonempty-table guard and explicit clean replacement\n");

foreach (['malformed', 'mismatch', 'insert', 'empty'] as $failure) {
    $rows = [sourceRow(1), sourceRow(2)];
    if ($failure === 'malformed') {
        $rows[1]['museumplus_xml'] = str_replace('</Object>', '<Broken></Object>', $rows[1]['museumplus_xml']);
    } elseif ($failure === 'mismatch') {
        $rows[1]['museumplus_id'] = '999';
    } elseif ($failure === 'empty') {
        $rows = [];
    }
    $db = database($rows);
    $db->tables['records']['old'] = ['oai_identifier' => 'old', 'oai_xml' => '<old/>'];
    if ($failure === 'insert') {
        $db->failInsertId = '2';
    }
    $saved = $db->tables;
    runRebuild($db, ['--replace' => true], $failure === 'empty' ? 'No source records' : 'Failed rebuilding MuseumPlus');
    check(sourceTablesUnchanged($db, $saved) && !$db->locked && $db->renames === 0, 'Failure changed a source table: '.$failure);
}
fwrite(STDOUT, "PASS: current/source tables preserved on malformed XML, wrong ID, insert failure or empty source\n");

$db = database([sourceRow(1)]);
$db->locked = true;
$saved = $db->tables;
runRebuild($db, [], 'already running');
check($db->tables === $saved && $db->locked, 'Touched an active import or released its lock.');
$db = database(array_map(sourceRow(...), range(1, 205)));
runRebuild($db);
check(count($db->tables['records']) === 205 && isset($db->tables['records']['current:205']), 'Paging lost records.');
check($db->commits === 3, 'Rebuild did not commit in batches of 100.');
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

$row = sourceRow(1);
$fragment = htmlspecialchars('<Digital_reference><digital_reference>https://www.museabrugge.be/collection/work/id/INV-2</digital_reference><digital_reference.description>workpid</digital_reference.description></Digital_reference>', ENT_QUOTES | ENT_XML1, 'UTF-8');
$row['museumplus_xml'] = str_replace('</Object>', '<Persistente_uri>'.$fragment.'</Persistente_uri></Object>', $row['museumplus_xml']);
$db = database([$row]);
$saved = $db->tables['records_import'];
$output = runRebuild($db);
check(str_contains($output, 'MuseumPlus 1 (INV-1), field Persistente_uri') && str_contains($output, '1 mapping warnings'), 'PID omission not reported by rebuild.');
check(!str_contains($db->tables['records']['current:1']['oai_xml'], 'INV-2') && $db->tables['records_import'] === $saved, 'Bad PID survived or source evidence changed.');
fwrite(STDOUT, "PASS: rejected PID reported with object identity, omitted from output, preserved in source\n");

// A completed full import leaves its source in records and empties records_import.
$db = database([]);
$rawRows = [sourceRow(1), sourceRow(2)];
foreach ($rawRows as $rawRow) {
    $rawRow['museumplus_xml'] = str_replace(['<ID>','</ID>','Naam_bewaarinstelling','Objectnummer','Titel_nl'], ['<priref>','</priref>','MuseumTxt','NumberTxt','TitleTxt_nl'], $rawRow['museumplus_xml']);
    $db->tables['records']['old:'.$rawRow['id']] = $rawRow;
}
$before = $db->tables;
runRebuild($db, ['--replace'=>true], useDefaultSource: true);
check(count($db->tables['records']) === 2 && isset($db->tables['records']['current:2']), 'Rebuild from records deleted its source.');
check($db->tables['records']['current:1']['museumplus_xml'] === $before['records']['old:1']['museumplus_xml'], 'In-place rebuild modified raw XML.');
check($db->tables['records']['current:1']['publish_brugge'] === 1 && str_contains($db->tables['records']['current:1']['oai_xml_native'], '<institution.name>Musea Brugge</institution.name>'), 'Revised source aliases or native profile not rebuilt.');
check($db->tables['records_import'] === [] && $db->tables['museumplus_import_state'] === $before['museumplus_import_state'], 'Completed-import rebuild changed staging/checkpoint.');
$db->failInsertId = '2';
$before = $db->tables;
runRebuild($db, ['--replace'=>true,'--source-table'=>'records'], 'Failed rebuilding MuseumPlus');
check(sourceTablesUnchanged($db, $before), 'Failed rebuild changed active/source records.');
runRebuild($db, ['--replace'=>true,'--source-table'=>'arbitrary'], 'source-table must be');
echo "PASS: completed-import rebuild, revised export aliases, native profiles and source preservation before publication\n";

$known = sourceRow(26245498, 'Museum voor Schone Kunsten Gent');
$known['museumplus_xml'] = str_replace('INV-26245498', 'S-40', $known['museumplus_xml']);
$db = database([$known]);
runRebuild($db);
$rebuilt = $db->tables['records']['current:26245498'];
check($rebuilt['axiell_priref'] === '310000001', 'Known Axiell identity was not indexed during rebuild.');
foreach (['oai_xml','oai_xml_native'] as $column) {
    $doc = new DOMDocument(); $doc->loadXML($rebuilt[$column], LIBXML_NONET);
    check($doc->getElementsByTagName('priref')->item(0)?->textContent === '310000001', 'Known Axiell identity differs between formats.');
}
check($rebuilt['museumplus_xml'] === $known['museumplus_xml'], 'Identity preservation changed source XML.');
$db = database([$known, sourceRow(310000001)]);
$before = $db->tables;
runRebuild($db, [], 'Failed rebuilding MuseumPlus');
check(sourceTablesUnchanged($db, $before) && !$db->locked, 'Rebuild priref collision changed source tables.');
echo "PASS: retained Axiell identity indexed in both formats; collision rollback preserves source\n";

// Prefer the full original response over the convenience Object column.
$rawRows = [sourceRow(901), sourceRow(902, 'Museum voor Schone Kunsten Gent'), sourceRow(903, 'Mu.ZEE, Kunstmuseum aan zee')];
$body = "<?xml version='1.0'?>\r\n<ObjectList>".implode("\r\n", array_column($rawRows, 'museumplus_xml'))."</ObjectList>\r\n";
$entry = $testArchive->capture(new Symfony\Component\HttpClient\MockHttpClient(new Symfony\Component\HttpClient\Response\MockResponse($body)),
    'https://museumplus.invalid/export', ['buffer'=>false], ['offset'=>0,'limit'=>3]);
foreach ($rawRows as &$rawRow) {
    $rawRow['raw_response_id'] = $entry['id'];
    $rawRow['museumplus_xml'] = '<stale-convenience-copy/>';
}
unset($rawRow);
$db = database($rawRows);
runRebuild($db);
foreach ([901,902,903] as $id) {
    $row = $db->tables['records']['current:'.$id];
    check($row['raw_response_id'] === $entry['id'] && str_contains($row['oai_xml'], 'Source title '.$id), 'Raw source not used or its link lost.');
    check($row['oai_xml_native'] !== null && $row['publish_vkc'] === 1, 'Rebuild from raw did not produce both views.');
}
check($testArchive->read($entry['id']) === $body, 'Rebuild modified original source bytes.');
$db = database([]);
foreach ($rawRows as $rawRow) $db->tables['records'][$rawRow['oai_identifier']] = $rawRow;
runRebuild($db, ['--source-table'=>'records','--replace'=>true]);
check($db->tables['records']['current:901']['raw_response_id'] === $entry['id'], 'In-place rebuild lost the raw link.');
$before = $db->tables;
file_put_contents($testArchive->directory().'/'.$entry['id'].'.xml', 'tampered');
runRebuild($db, ['--source-table'=>'records','--replace'=>true], 'checksum differs');
check(sourceTablesUnchanged($db, $before) && !$db->locked, 'Bad raw source silently fell back or destroyed current records.');
echo "PASS: verified raw sources rebuild four profiles offline; source links survive; corruption rolls back without fallback\n";

// Removing publication flags must clear old output, even when every record is withdrawn.
$db = database([sourceRow(701), sourceRow(702), sourceRow(703), sourceRow(704)]);
runRebuild($db);
$expected = [701=>[], 702=>['musea_brugge'], 703=>['vkc'], 704=>['musea_brugge','vkc']];
$sources = [];
foreach ([701=>[], 702=>['Extranet'], 703=>['VKC datahub'], 704=>['Extranet','VKC datahub']] as $id=>$flags) {
    $sources[$id] = sourceRow($id, flags: $flags)['museumplus_xml'];
    $db->tables['records']['current:'.$id]['museumplus_xml'] = $sources[$id];
}
runRebuild($db, ['--replace'=>true], useDefaultSource: true);
foreach ($expected as $id=>$sets) {
    $row = $db->tables['records']['current:'.$id];
    check($row['publish_brugge'] === (int) in_array('musea_brugge', $sets, true) && $row['publish_vkc'] === (int) in_array('vkc', $sets, true), 'Rebuild did not apply independent publication flags.');
    check(($row['oai_xml'] !== '') === in_array('vkc', $sets, true) && ($row['oai_xml_native'] !== null) === in_array('musea_brugge', $sets, true), 'Withdrawn output was not cleared.');
    check($row['museumplus_xml'] === $sources[$id] && $row['axiell_priref'] === (string) $id, 'Withdrawal changed source or identity.');
    $db->tables['records']['current:'.$id]['museumplus_xml'] = sourceRow($id, flags: [])['museumplus_xml'];
}
$output = runRebuild($db, ['--replace'=>true], useDefaultSource: true);
check(count($db->tables['records']) === 4 && str_contains($output, '0 belong to a publication set'), 'All-unpublished rebuild was rejected or deleted sources.');
foreach ($db->tables['records'] as $row) {
    check($row['publish_brugge'] === 0 && $row['publish_vkc'] === 0 && $row['oai_xml'] === '' && $row['oai_xml_native'] === null, 'All-unpublished rebuild kept old OAI output.');
}
echo "PASS: rebuild revokes individual and all publications without deleting sources or changing identifiers\n";

// A later failed batch leaves committed scratch rows, but never changes the active dataset.
$db = database(array_map(sourceRow(...), range(1, 205)));
$db->tables['records']['old:999'] = sourceRow(999);
$before = $db->tables;
$db->beforeRebuildInsert = static function (MemoryImportConnection $db) use ($before): void {
    check(sourceTablesUnchanged($db, $before), 'A source or active table changed while the rebuild was being filled.');
};
$db->failInsertId = '102';
runRebuild($db, ['--replace'=>true], 'Failed rebuilding MuseumPlus 102');
check(sourceTablesUnchanged($db, $before) && $db->renames === 0 && !$db->locked, 'Partial rebuild was published or retained its lock.');
check(count($db->tables['records_rebuild']) === 100, 'Failed batch did not roll back independently of earlier batches.');
$db->failInsertId = null;
runRebuild($db, ['--replace'=>true]);
check(count($db->tables['records']) === 205 && !isset($db->tables['records']['old:999']), 'Restart reused partial rows or kept obsolete active records.');
foreach (['records_import', 'museumplus_import_state', 'museumplus_import_errors'] as $table) {
    check($db->tables[$table] === $before[$table], 'Restart changed '.$table);
}
echo "PASS: current dataset remains readable during all batches; failed scratch batches are discarded on retry\n";

$db = database([sourceRow(1), sourceRow(2)]);
$before = $db->tables;
$db->beforeRebuildInsert = static function (MemoryImportConnection $db, array $data): void {
    if ($data['museumplus_id'] === '2') unset($db->tables['records_rebuild']['current:1']);
};
runRebuild($db, [], 'Rebuild count does not match the source');
check(sourceTablesUnchanged($db, $before) && $db->renames === 0, 'Incomplete scratch table was published.');
echo "PASS: row-count validation refuses an incomplete rebuild before swapping\n";

// Losing the RENAME response may mean the old OR new table is active. Never swap back.
foreach (['before', 'after'] as $failure) {
    $db = database([sourceRow(1), sourceRow(2)]);
    $db->tables['records']['old:999'] = sourceRow(999);
    $before = $db->tables;
    $db->failRename = $failure;
    runRebuild($db, ['--replace'=>true], 'atomic rename');
    check(!$db->locked, 'Failed swap retained the import/rebuild lock.');
    if ($failure === 'before') {
        check($db->tables['records'] === $before['records'] && $db->renames === 0, 'Rejected swap changed active records.');
    } else {
        check(array_keys($db->tables['records']) === ['current:1','current:2'] && $db->renames === 1, 'Lost response reversed or partially applied the swap.');
        check($db->tables['records_rebuild_old'] === $before['records'], 'Lost response destroyed the previous dataset.');
    }
    $db->failRename = null;
    runRebuild($db, ['--replace'=>true]);
    check(array_keys($db->tables['records']) === ['current:1','current:2'], 'Retry restored obsolete records or lost rebuilt records.');
    foreach (['records_import', 'museumplus_import_state', 'museumplus_import_errors'] as $table) {
        check($db->tables[$table] === $before[$table], 'Swap failure/retry changed '.$table);
    }
}
echo "PASS: failed and uncertain atomic swaps retry safely without reverting publication or touching import progress\n";

// Cleanup happens after publication and cannot roll it back.
$db = database([sourceRow(1)]);
$db->tables['records']['old:999'] = sourceRow(999);
$before = $db->tables;
$db->failStatement = 'DROP TABLE records_rebuild_old';
$output = runRebuild($db, ['--replace'=>true]);
check(str_contains($output, 'Rebuild published, but') && isset($db->tables['records']['current:1']), 'Cleanup error hid a successful publication.');
check($db->tables['records_rebuild_old'] === $before['records'] && !$db->locked, 'Cleanup failure lost the old table or retained the lock.');
$db->failStatement = null;
runRebuild($db, ['--replace'=>true]);
check(!isset($db->tables['records_rebuild_old']) && array_keys($db->tables['records']) === ['current:1'], 'Retry did not clean up the old table safely.');
echo "PASS: failed post-publication cleanup is reported without reverting the active dataset\n";
