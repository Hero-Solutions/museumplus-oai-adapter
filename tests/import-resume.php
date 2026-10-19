<?php

declare(strict_types=1);

// Run with: php -n tests/import-resume.php
// No kernel/.env, database driver or real HTTP client. All settings are fake.

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Command\ImportMuseumPlusRecordsCommand;
use App\Import\MuseumPlusRecordBuilder;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\XmlValueExtractor;
use App\Normalizer\CreatorNormalizer;
use App\Normalizer\DimensionNormalizer;
use App\Normalizer\LabelNormalizer;
use App\Tests\Support\MemoryImportConnection;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/MemoryImportConnection.php';
require __DIR__.'/Support/TemporaryRawArchive.php';

$testArchive = App\Tests\Support\temporaryRawArchive();

$_ENV = [
    'MUSEUMPLUS_EXPORT_URL' => 'https://museumplus.invalid/export',
    'MUSEUMPLUS_USERNAME' => 'fake-user',
    'MUSEUMPLUS_PASSWORD' => 'fake-password',
    'MUSEUMPLUS_SEARCH_FIELD_PATH' => 'test.field',
    'MUSEUMPLUS_SEARCH_OPERAND' => 'test-value',
    'MUSEUMPLUS_ORG_UNITS' => '',
    'MUSEUMPLUS_OAI_IDENTIFIER_PREFIX' => 'test:',
    'MUSEUMPLUS_DEFAULT_SET_SPEC' => 'test',
    'MUSEUMPLUS_TIMEOUT_SECONDS' => '1',
];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function batch(array $ids): MockResponse
{
    return new MockResponse('<ObjectList>'.implode('', array_map(
        static fn (string $id): string => '<Object><ID>'.$id.'</ID></Object>',
        $ids,
    )).'</ObjectList>');
}

function database(): MemoryImportConnection
{
    $db = new MemoryImportConnection();
    $db->tables['records']['test:old'] = ['oai_identifier' => 'test:old', 'datestamp' => '2020-01-01 00:00:00'];

    return $db;
}

/** A fresh command and HTTP client simulate a new process using the saved database. */
function runImport(MemoryImportConnection $db, callable $factory, array $options = [], ?string $expectedError = null, ?App\Import\RawResponseArchive $archive = null): array
{
    global $testArchive;
    $requests = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $factory): MockResponse {
        check($url === 'https://museumplus.invalid/export' && $method === 'POST', 'Only the fake export may be requested.');
        preg_match('/limit="(\d+)" offset="(\d+)"/', $options['body'], $match);
        $offset = (int) $match[2];
        $limit = (int) $match[1];
        $requests[] = [$offset, $limit];

        return $factory($offset, $limit, $options['body']);
    });
    $command = new ImportMuseumPlusRecordsCommand(
        $client,
        new MuseumPlusExportParser(new XmlValueExtractor()),
        new MuseumPlusRecordBuilder(
            new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()),
            new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'))),
        ),
        $db,
        $archive ?? $testArchive,
    );
    $tester = new CommandTester($command);
    $error = null;

    try {
        check($tester->execute($options, ['interactive' => false]) === 0, 'Command failed.');
    } catch (RuntimeException $exception) {
        $error = $exception;
    }

    if ($expectedError === null) {
        check($error === null, 'Unexpected error: '.($error?->getMessage() ?? ''));
    } else {
        check($error !== null && str_contains($error->getMessage(), $expectedError), 'Expected '.$expectedError.'; got '.($error?->getMessage() ?? 'success'));
    }

    return $requests;
}

function noFetch(): MockResponse
{
    throw new LogicException('No HTTP request should be made.');
}

function interruptedImport(): MemoryImportConnection
{
    $db = database();
    runImport($db, static fn (int $offset): MockResponse => $offset === 0 ? batch(['1', '2']) : new MockResponse('Unauthorized', ['http_code' => 401]), ['--batch-size' => '2'], 'HTTP 401');

    return $db;
}

$db = database();
runImport($db, static fn (int $offset): MockResponse => $offset === 0 ? batch(['1', '2']) : new MockResponse('Bad gateway', ['http_code' => 502]), ['--batch-size' => '2'], 'after 4 attempts');
check(array_keys($db->tables['records']) === ['test:old'], 'Failed import replaced published records.');
check(count($db->tables['records_import']) === 2 && $db->tables['museumplus_import_state'][1]['next_offset'] === 2, 'First committed batch was lost.');
check(!$db->locked, 'Lock was not released on failure.');
$saved = $db->tables;
$requests = runImport($db, static fn (): MockResponse => new MockResponse('Offline', ['http_code' => 503]), ['--resume' => true, '--batch-size' => '2'], 'after 4 attempts');
check($db->tables === $saved && $requests === array_fill(0, 4, [2, 2]), 'Extended outage changed progress or refetched offset zero.');
$requests = runImport($db, static fn (): MockResponse => batch(['3', '4']), ['--batch-size' => '3']);
check($requests === [[2, 3]], 'Automatic resume or batch size change used the wrong offset.');
check(array_keys($db->tables['records']) === ['test:1', 'test:2', 'test:3', 'test:4'], 'Resumed result has missing or duplicate records.');
check(count(array_unique(array_column($db->tables['records'], 'datestamp'))) === 1, 'Resume changed the import datestamp.');
check($db->tables['museumplus_import_state'][1]['status'] === 'completed', 'Publication was not recorded.');
check(runImport($db, noFetch(...), ['--resume' => true]) === [] && $db->renames === 1, 'Completed import was swapped a second time.');
fwrite(STDOUT, "PASS: repeated outage, automatic resume, changed batch size and completed resume\n");

$db = interruptedImport();
$saved = $db->tables;
$db->failCheckpoint = true;
runImport($db, static fn (): MockResponse => batch(['3']), ['--resume' => true], 'checkpoint failure');
check($db->tables === $saved, 'Records were committed without their checkpoint.');
$db->failCheckpoint = false;
check(runImport($db, static fn (): MockResponse => batch(['3']), ['--resume' => true]) === [[2, 1000]], 'Failed batch was skipped.');
fwrite(STDOUT, "PASS: checkpoint failure rolls back records and replays only the failed batch\n");

$db = database();
$db->failInsertId = '2';
runImport($db, static fn (): MockResponse => batch(['1', '2']), ['--batch-size' => '2'], 'record insert failure');
check($db->tables['records_import'] === [] && $db->tables['museumplus_import_state'][1]['next_offset'] === 0, 'Half a batch survived rollback.');
$db->failInsertId = null;
runImport($db, static fn (): MockResponse => batch(['1', '2']), ['--resume' => true]);
check(count($db->tables['records']) === 2, 'Insert failure could not be resumed.');
fwrite(STDOUT, "PASS: failure halfway through saving a batch is atomic\n");

$db = interruptedImport();
$saved = $db->tables;
$_ENV['MUSEUMPLUS_SEARCH_OPERAND'] = 'different-filter';
runImport($db, noFetch(...), ['--resume' => true], 'settings changed');
$_ENV['MUSEUMPLUS_SEARCH_OPERAND'] = 'test-value';
runImport($db, static fn (): MockResponse => new MockResponse('<html><body>Offline</body></html>'), ['--resume' => true], 'Expected an ObjectList');
runImport($db, static fn (): MockResponse => new MockResponse('<ObjectList><Object>'), ['--resume' => true], 'Could not parse');
check($db->tables === $saved, 'Bad configuration or invalid XML changed saved data.');
unset($db->tables['records_import']['test:2']);
runImport($db, noFetch(...), ['--resume' => true], 'does not match');
fwrite(STDOUT, "PASS: changed filter, error pages, broken XML and mismatched staging are rejected\n");

$db = interruptedImport();
$legacyHash = hash('sha256', json_encode(['https://museumplus.invalid/export', 'test.field', 'test-value', 'test:', 'test'], JSON_THROW_ON_ERROR));
check($db->tables['museumplus_import_state'][1]['source_hash'] !== $legacyHash, 'Old mapping checkpoint was accepted by the new profiles.');
$newState = $db->tables;
$db->tables['museumplus_import_state'][1]['source_hash'] = $legacyHash;
runImport($db, noFetch(...), ['--resume' => true], 'settings changed');
check($db->tables['museumplus_import_state'][1]['source_hash'] === $legacyHash, 'Refusal modified the old checkpoint.');
$db->tables = $newState;
$saved = $db->tables;
$_ENV['MUSEUMPLUS_ORG_UNITS'] = 'org_1_TestMuseum,org_2_OtherMuseum';
runImport($db, noFetch(...), ['--resume' => true], 'settings changed');
check($db->tables === $saved, 'Adding an organisation filter changed the old checkpoint.');

$db = interruptedImport();
$saved = $db->tables;
foreach (['org_1_TestMuseum', 'org_1_TestMuseum,org_3_ThirdMuseum', ''] as $changedSelection) {
    $_ENV['MUSEUMPLUS_ORG_UNITS'] = $changedSelection;
    runImport($db, noFetch(...), ['--resume' => true], 'settings changed');
    check($db->tables === $saved, 'Changing or removing the organisation filter changed saved data.');
}
$_ENV['MUSEUMPLUS_ORG_UNITS'] = "\norg_2_OtherMuseum\r\n  org_1_TestMuseum, org_2_OtherMuseum\n";
$requests = runImport($db, static function (int $offset, int $limit, string $body): MockResponse {
    check(str_contains($body, '<and><equalsField fieldPath="test.field" operand="test-value"/><or>'), 'Category and organisations were not combined with AND/OR.');
    check(substr_count($body, 'fieldPath="__orgUnit"') === 2, 'The import omitted or duplicated an organisation.');
    check(str_contains($body, 'operand="org_1_TestMuseum"') && str_contains($body, 'operand="org_2_OtherMuseum"'), 'The import used the wrong organisations.');

    return batch(['3']);
}, ['--resume' => true]);
check($requests === [[2, 1000]], 'Reordering or repeating organisation codes prevented a safe resume.');
$_ENV['MUSEUMPLUS_ORG_UNITS'] = '';
fwrite(STDOUT, "PASS: old mapping checkpoints refused; organisation filters reach the export; selection changes block resume, formatting changes preserve it\n");

$db = database();
$newExport = file_get_contents(__DIR__.'/fixtures/museumplus-priref.xml');
$requests = runImport($db, static fn (): MockResponse => new MockResponse($newExport));
check($requests === [[0, 1000]] && array_keys($db->tables['records']) === ['test:25001583'], 'The new export was skipped instead of stored.');
$row = $db->tables['records']['test:25001583'];
check($row['object_number'] === 'BL-2021-002' && $row['set_spec'] === 'musea_brugge' && str_contains($row['museumplus_xml'], 'Heilige Martinus'), 'The new export lost source metadata.');
check($row['publish_brugge'] === 0 && $row['publish_vkc'] === 0 && $row['oai_xml'] === '' && $row['oai_xml_native'] === null, 'The unflagged export was published.');
check($testArchive->read($row['raw_response_id']) === $newExport, 'Unpublished response bytes were lost.');
check($db->tables['museumplus_import_state'][1]['stored'] === 1 && $db->tables['museumplus_import_state'][1]['skipped'] === 0, 'The new export advanced as skipped.');
fwrite(STDOUT, "PASS: the unflagged priref export is stored byte-exactly without OAI metadata\n");

foreach ([
    '<Object><UnknownId>123</UnknownId><CreatorTxt>Test</CreatorTxt></Object>' => 'without an ID or priref',
    '<Object><ID>123</ID><priref>456</priref></Object>' => 'conflicting ID and priref',
] as $object => $message) {
    $db = interruptedImport();
    $saved = $db->tables;
    $requests = runImport($db, static fn (): MockResponse => new MockResponse('<ObjectList>'.$object.'</ObjectList>'), ['--resume' => true], $message);
    check($requests === [[2, 1000]] && $db->tables === $saved && !$db->locked && $db->renames === 0, 'An identity format error advanced, retried, skipped, published or changed stored data.');
}
fwrite(STDOUT, "PASS: identity format failures leave the checkpoint and both record tables unchanged\n");

$db = database();
$requests = runImport($db, static fn (int $offset): MockResponse => match ($offset) {
    0 => new MockResponse('<ObjectList><Object><priref>123</priref><TitleTxt_nl>unfinished'),
    1 => new MockResponse($newExport),
    default => batch([]),
}, ['--batch-size' => '1']);
check($requests === [[0, 1], [1, 1], [2, 1]] && array_keys($db->tables['records']) === ['test:25001583'], 'A truncated priref export broke isolation or lost the next record.');
check($db->tables['museumplus_import_errors'][1]['museumplus_id'] === '123', 'A truncated priref export was logged without its identifier.');
fwrite(STDOUT, "PASS: truncated priref exports retain existing isolation and error logging\n");

$db = interruptedImport();
$db->tables['museumplus_import_state'][1]['next_offset'] = 394000;
$db->tables['museumplus_import_state'][1]['skipped'] = 393998;
$saved = $db->tables;
$truncatedXml = '<ObjectList><Object><ID>discarded</ID></Object><Object><ID>3</ID><Tentoonstelling>';
$requests = runImport($db, static fn (): MockResponse => new MockResponse($truncatedXml), ['--resume' => true], 'Could not parse');
check($requests === [[394000, 1000]] && $db->tables === $saved, 'Unidentifiable truncated XML was retried, changed the checkpoint or stored partial records.');
check(!$db->locked && $db->renames === 0, 'Truncated XML left a lock or published incomplete data.');
$requests = runImport($db, static function () use ($db, $saved): MockResponse {
    check($db->tables === $saved, 'A partial XML document was stored before resuming.');

    return batch(['3']);
}, ['--resume' => true]);
check($requests === [[394000, 1000]], 'Resume refetched earlier batches or skipped the failed offset.');
check(array_keys($db->tables['records']) === ['test:1', 'test:2', 'test:3'], 'Recovered batch contains discarded partial records.');
check($db->tables['museumplus_import_state'][1]['next_offset'] === 394001 && $db->renames === 1, 'Recovered batch advanced or published incorrectly.');
fwrite(STDOUT, "PASS: unidentifiable truncated XML stops immediately and resumes without partial records\n");

$db = interruptedImport();
$db->tables['museumplus_import_state'] = [];
$saved = $db->tables;
runImport($db, noFetch(...), [], 'already contains data');
runImport($db, noFetch(...), ['--resume' => true], 'No saved checkpoint');
check($db->tables === $saved, 'Legacy staging data was discarded.');
check($db->tables === $saved, 'Unversioned legacy staging was changed.');
fwrite(STDOUT, "PASS: unversioned legacy staging preserved and refused before any request\n");

$db = interruptedImport();
$scans = $db->importScans;
$truncates = $db->importTruncates;
$_ENV['MUSEUMPLUS_SEARCH_OPERAND'] = 'new-export-filter';
$requests = runImport($db, static function () use ($db, $scans, $truncates): MockResponse {
    check($db->importScans === $scans, 'Restart scanned the old staging table before discarding it.');
    check($db->importTruncates === $truncates + 1 && $db->tables['records_import'] === [], 'Restart did not truncate staging before fetching.');
    check($db->tables['museumplus_import_state'][1]['status'] === 'fetching' && $db->tables['museumplus_import_state'][1]['next_offset'] === 0, 'Restart did not finish resetting before fetching.');
    check(array_keys($db->tables['records']) === ['test:old'], 'Restart cleared published records before the new import finished.');

    return batch(['9']);
}, ['--restart' => true]);
$_ENV['MUSEUMPLUS_SEARCH_OPERAND'] = 'test-value';
check($requests === [[0, 1000]] && array_keys($db->tables['records']) === ['test:9'], 'Explicit restart did not reset the staging import.');
fwrite(STDOUT, "PASS: explicit restart truncates without scanning, accepts a changed source and starts from zero\n");

$db = interruptedImport();
$saved = $db->tables;
$truncates = $db->importTruncates;
$db->failStateInsert = true;
runImport($db, noFetch(...), ['--restart' => true], 'checkpoint insert failure');
check($db->tables === $saved && $db->importTruncates === $truncates && !$db->locked, 'A failed reset marker changed data or left a lock.');
$db->failStateInsert = false;
check(runImport($db, static fn (): MockResponse => batch(['3']), ['--resume' => true]) === [[2, 1000]], 'A failed reset marker made the original checkpoint unusable.');
fwrite(STDOUT, "PASS: failure saving the reset marker preserves the original import\n");

foreach (['before', 'after', 'checkpoint'] as $failurePoint) {
    $db = interruptedImport();
    $staging = $db->tables['records_import'];
    $published = $db->tables['records'];
    $db->failTruncate = $failurePoint === 'checkpoint' ? null : $failurePoint;
    $db->failCheckpoint = $failurePoint === 'checkpoint';
    runImport($db, noFetch(...), ['--restart' => true], $failurePoint === 'checkpoint' ? 'checkpoint failure' : $failurePoint.' truncate');
    check($db->tables['records_import'] === ($failurePoint === 'before' ? $staging : []), 'Truncate failure had unexpected staging contents.');
    check($db->tables['museumplus_import_state'][1]['status'] === 'resetting' && $db->tables['records'] === $published && !$db->locked, 'An interrupted reset left a usable checkpoint or changed published records.');

    $saved = $db->tables;
    foreach ([[], ['--resume' => true]] as $options) {
        runImport($db, noFetch(...), $options, 'Run again with --restart');
        check($db->tables === $saved && $db->renames === 0, 'An interrupted reset resumed or published an incomplete import.');
    }

    $db->failTruncate = null;
    $db->failCheckpoint = false;
    $requests = runImport($db, static fn (): MockResponse => batch(['9']), ['--restart' => true]);
    check($requests === [[0, 1000]] && array_keys($db->tables['records']) === ['test:9'], 'Restart could not recover an interrupted reset.');
}
fwrite(STDOUT, "PASS: failures before/after truncate and before fetching require an explicit restart\n");

$db = database();
runImport($db, static fn (int $offset): MockResponse => $offset === 0 ? batch(['1', '']) : new MockResponse('Unauthorized', ['http_code' => 401]), ['--batch-size' => '2'], 'HTTP 401');
check(count($db->tables['records_import']) === 1 && $db->tables['museumplus_import_state'][1]['next_offset'] === 2, 'Offset was derived from stored row count instead of fetched records.');
runImport($db, static fn (): MockResponse => batch([]), ['--resume' => true]);
check(count($db->tables['records']) === 1, 'Empty last page prevented publishing an earlier batch.');
fwrite(STDOUT, "PASS: skipped records advance the offset and an empty final page publishes saved batches\n");

$db = database();
runImport($db, static fn (): MockResponse => batch([]), [], 'zero records');
check(array_keys($db->tables['records']) === ['test:old'], 'Empty import replaced published records.');
runImport($db, noFetch(...), ['--resume' => true], 'zero records');
check(array_keys($db->tables['records']) === ['test:old'], 'Empty import replaced published records.');
fwrite(STDOUT, "PASS: empty import protection remains effective on resume\n");

foreach (['before', 'after'] as $failurePoint) {
    $db = database();
    $db->failRename = $failurePoint;
    runImport($db, static fn (): MockResponse => batch(['1']), [], $failurePoint === 'before' ? 'before atomic rename' : 'after atomic rename');
    check($db->tables['museumplus_import_state'][1]['status'] === ($failurePoint === 'before' ? 'ready' : 'completed'), 'Publication status was inconsistent.');
    $db->failRename = null;
    runImport($db, noFetch(...), ['--resume' => true]);
    check($db->renames === 1 && array_keys($db->tables['records']) === ['test:1'], 'Resume published twice or lost the new records.');
    runImport($db, static fn (): MockResponse => batch(['2']));
    check($db->renames === 2 && array_keys($db->tables['records']) === ['test:2'], 'A new import after completion failed.');
}
fwrite(STDOUT, "PASS: interruption before or after publication cannot swap records back\n");

$db = interruptedImport();
runImport($db, noFetch(...), ['--resume' => true, '--restart' => true], 'cannot');
$db->locked = true;
runImport($db, noFetch(...), ['--resume' => true], 'already running');
echo "PASS: incompatible options and concurrent import protection\n";

function brokenObject(int $id): string
{
    return '<ObjectList><Object><ID>'.$id.'</ID><Tentoonstelling>unfinished';
}

/** Simulate an export that truncates when it reaches a run of broken objects. */
function damagedExport(int $end, int $brokenOffset, int $brokenCount = 1): Closure
{
    return static function (int $offset, int $limit) use ($end, $brokenOffset, $brokenCount): MockResponse {
        $xml = '<ObjectList>';
        for ($position = $offset; $position < min($end, $offset + $limit); ++$position) {
            $xml .= '<Object><ID>'.($position + 1).'</ID>';
            if ($position >= $brokenOffset && $position < $brokenOffset + $brokenCount) {
                return new MockResponse($xml.'<Tentoonstelling>unfinished');
            }
            $xml .= '</Object>';
        }

        return new MockResponse($xml.'</ObjectList>');
    };
}

$db = interruptedImport();
$db->tables['museumplus_import_state'][1]['next_offset'] = 394800;
$requests = runImport($db, damagedExport(394812, 394807), ['--resume' => true, '--batch-size' => '8']);
check($requests === [
    [394800, 8], [394800, 4], [394804, 4], [394804, 2], [394806, 2], [394806, 1],
    [394807, 1], [394808, 1], [394809, 2], [394811, 4],
], 'Isolation did not halve at the same offset, probe the next object or gradually grow the batch size.');
check(count($db->tables['records']) === 13 && !isset($db->tables['records']['test:394808']), 'Isolation lost valid records or imported the broken object.');
check(isset($db->tables['records']['test:1'], $db->tables['records']['test:394812']), 'Earlier staging data or the final object was lost.');
$state = $db->tables['museumplus_import_state'][1];
check($state['next_offset'] === 394812 && $state['skipped'] === 1 && $state['status'] === 'completed', 'Automatic skip checkpoint/count was incorrect.');
$log = $db->tables['museumplus_import_errors'][1];
check($log['source_offset'] === 394807 && $log['museumplus_id'] === '394808' && $log['response_body'] === brokenObject(394808), 'Error log lost the exact offset, ID or raw bytes.');
check($log['source_hash'] === $state['source_hash'] && $log['import_datestamp'] === $state['datestamp'] && str_contains($log['error_message'], 'Could not parse MuseumPlus response'), 'Error log lacks import context or the error.');
runImport($db, static fn (): MockResponse => batch(['99']), ['--restart' => true]);
check($db->tables['museumplus_import_errors'][1] === $log, 'Publishing or restarting erased the error evidence.');
fwrite(STDOUT, "PASS: halving isolates one object, logs it, preserves all other records and restores batch size\n");

$db = database();
$attempt = 0;
$requests = runImport($db, static function (int $offset, int $limit) use (&$attempt): MockResponse {
    if (++$attempt === 1) {
        return new MockResponse(brokenObject(1));
    }

    return damagedExport(6, -1)($offset, $limit);
}, ['--batch-size' => '5']);
check($requests === [[0, 5], [0, 2], [2, 2], [4, 1], [5, 5]], 'Odd-sized recovery interval skipped a position or failed to restore the original size.');
check(count($db->tables['records']) === 6 && $db->tables['museumplus_import_errors'] === [], 'Transient batch truncation skipped or logged a healthy record.');
fwrite(STDOUT, "PASS: valid smaller batches recover without skipping, including odd batch sizes\n");
$db = database();
$requests = runImport($db, static fn (int $offset): MockResponse => match ($offset) {
    0 => new MockResponse(brokenObject(1)),
    1 => batch(['2']),
    default => batch([]),
}, ['--batch-size' => '1']);
check($requests === [[0, 1], [1, 1], [2, 1]], 'Invalid singleton was retried instead of skipped immediately.');
check(array_keys($db->tables['records']) === ['test:2'] && $db->tables['museumplus_import_errors'][1]['response_body'] === brokenObject(1), 'Immediate skip failed to log the object or import its successor.');
fwrite(STDOUT, "PASS: the first invalid singleton response is logged and skipped without retries\n");

foreach (['failErrorLog' => 'error log failure', 'failCheckpoint' => 'checkpoint failure'] as $flag => $message) {
    $db = interruptedImport();
    $saved = $db->tables;
    $db->$flag = true;
    runImport($db, static fn (): MockResponse => new MockResponse(brokenObject(3)), ['--resume' => true, '--batch-size' => '1'], $message);
    check($db->tables === $saved && !$db->locked, 'Failed log/checkpoint write did not roll back the complete skip.');
}
fwrite(STDOUT, "PASS: error evidence and automatic skip checkpoint commit atomically\n");

$db = interruptedImport();
runImport($db, static fn (int $offset): MockResponse => $offset === 2 ? new MockResponse(brokenObject(3)) : new MockResponse('Unauthorized', ['http_code' => 401]), ['--resume' => true, '--batch-size' => '1'], 'HTTP 401');
check($db->tables['museumplus_import_state'][1]['next_offset'] === 3 && count($db->tables['museumplus_import_errors']) === 1, 'Automatic skip was lost after a later failure.');
$requests = runImport($db, damagedExport(4, -1), ['--resume' => true]);
check($requests === [[3, 1], [4, 2]] && count($db->tables['museumplus_import_errors']) === 1, 'Resume did not probe the next position or double-logged the skipped object.');
fwrite(STDOUT, "PASS: automatic skip survives interruption and resume never replays it\n");

foreach (['HTML', 'empty', 'multiple objects'] as $failure) {
    $db = interruptedImport();
    $saved = $db->tables;
    $requests = runImport($db, static function () use ($failure): MockResponse {
        return match ($failure) {
            'HTML' => new MockResponse('<html><body>Service unavailable</body></html>'),
            'empty' => new MockResponse(''),
            'multiple objects' => new MockResponse('<ObjectList><Object><ID>3</ID></Object><Object><ID>4</ID><Broken>'),
        };
    }, ['--resume' => true, '--batch-size' => '1'], 'invalid response at offset 2');
    check($db->tables === $saved && $requests === [[2, 1]], $failure.': uncertain failure skipped a record or retried.');
}
fwrite(STDOUT, "PASS: error pages, empty responses and ignored limits stop immediately without skipping\n");

foreach (['HTTP', 'transport'] as $failure) {
    $db = interruptedImport();
    $attempt = 0;
    $requests = runImport($db, static function (int $offset) use ($failure, &$attempt): MockResponse {
        if (++$attempt === 1) {
            if ($failure === 'transport') {
                throw new Symfony\Component\HttpClient\Exception\TransportException('Simulated interruption');
            }

            return new MockResponse('Unavailable', ['http_code' => 503]);
        }

        return match ($offset) {
            2 => new MockResponse(brokenObject(3)),
            3 => batch(['4']),
            default => batch([]),
        };
    }, ['--resume' => true, '--batch-size' => '1']);
    check($requests === [[2, 1], [2, 1], [3, 1], [4, 1]], 'Retry must apply to '.$failure.' failures only, not the following XML error.');
    check(array_keys($db->tables['records']) === ['test:1', 'test:2', 'test:4'] && count($db->tables['museumplus_import_errors']) === 1, 'Mixed failure skipped the wrong record or lost its log.');
}
fwrite(STDOUT, "PASS: transport and HTTP retries remain, followed by an immediate skip on invalid XML\n");

$db = interruptedImport();
$requests = runImport($db, damagedExport(30, 2, 8), ['--resume' => true, '--batch-size' => '8']);
check($requests === [
    [2, 8], [2, 4], [2, 2], [2, 1], [3, 1], [4, 1], [5, 1], [6, 1], [7, 1], [8, 1], [9, 1],
    [10, 1], [11, 2], [13, 4], [17, 8], [25, 8],
], 'Consecutive errors repeated the halving sequence or recovery did not grow up to the configured size.');
check(array_column($db->tables['museumplus_import_errors'], 'source_offset') === range(2, 9), 'Every broken position must be logged exactly once.');
check(count($db->tables['records']) === 22 && $db->tables['museumplus_import_state'][1]['next_offset'] === 30 && $db->tables['museumplus_import_state'][1]['skipped'] === 8, 'Consecutive skips lost good records or advanced incorrectly.');
fwrite(STDOUT, "PASS: long runs of invalid objects use one request each, then recover with growing batches\n");

$db = interruptedImport();
$requests = runImport($db, static fn (int $offset, int $limit): MockResponse => $offset === 6
    ? new MockResponse('Unauthorized', ['http_code' => 401])
    : damagedExport(30, 2, 8)($offset, $limit), ['--resume' => true, '--batch-size' => '8'], 'HTTP 401');
check($db->tables['museumplus_import_state'][1]['next_offset'] === 6 && $db->tables['museumplus_import_state'][1]['consecutive_invalid'] === 4, 'Interrupted streak lost its checkpoint.');
$requests = runImport($db, damagedExport(30, 2, 8), ['--resume' => true]);
check($requests === [[6, 1], [7, 1], [8, 1], [9, 1], [10, 1], [11, 2], [13, 4], [17, 8], [25, 16]], 'Resuming a broken streak restarted at the full batch size.');
check(array_column($db->tables['museumplus_import_errors'], 'source_offset') === range(2, 9) && $db->tables['museumplus_import_state'][1]['consecutive_invalid'] === 0, 'Resume duplicated error logs or did not reset the streak on valid data.');
fwrite(STDOUT, "PASS: resume remembers consecutive invalid objects and continues with single-record requests\n");

$db = database();
$requests = runImport($db, damagedExport(16, 0), ['--batch-size' => '5']);
check($requests === [[0, 5], [0, 2], [0, 1], [1, 1], [2, 2], [4, 4], [8, 5], [13, 5]], 'Recovery growth exceeded an odd configured batch size or skipped positions.');
check(count($db->tables['records']) === 15, 'Recovery to an odd batch size lost records.');
fwrite(STDOUT, "PASS: gradual recovery is capped at the configured batch size, including odd sizes\n");

// Retain the verified Axiell identity during a full import, in both output views.
$known = '<Object><priref>26245498</priref>'
    .'<MuseumTxt>&lt;institution.name>Museum voor Schone Kunsten Gent&lt;/institution.name></MuseumTxt>'
    .'<WebPublicationTxt>&lt;webpublication>Extranet&lt;/webpublication>&lt;webpublication>VKC datahub&lt;/webpublication></WebPublicationTxt>'
    .'<NumberTxt>&lt;object_number>S-40&lt;/object_number></NumberTxt></Object>';
$db = database();
runImport($db, static fn (): MockResponse => new MockResponse('<ObjectList>'.$known.'</ObjectList>'));
$row = $db->tables['records']['test:26245498'];
check($row['axiell_priref'] === '310000001' && $row['publish_msk'] === 1 && $row['publish_vkc'] === 1, 'Full import lost the known identity or museum/VKC pair.');
foreach (['oai_xml', 'oai_xml_native'] as $column) {
    $xml = new DOMDocument(); $xml->loadXML($row[$column], LIBXML_NONET);
    check($xml->getElementsByTagName('priref')->item(0)?->textContent === '310000001', 'Imported profile changed the Axiell priref.');
}
$db = database();
runImport($db, static fn (): MockResponse => new MockResponse('<ObjectList>'.$known.'<Object><priref>310000001</priref></Object></ObjectList>'), [], 'Duplicate Axiell priref');
check(array_keys($db->tables['records']) === ['test:old'] && $db->tables['records_import'] === [] && $db->tables['museumplus_import_state'][1]['next_offset'] === 0, 'Axiell identity collision replaced records or advanced the checkpoint.');
echo "PASS: full import retains known Axiell identities in both views; collisions roll back the batch\n";

// The raw response is the byte-exact source; mapped Object XML is only a convenience copy.
$db = database();
$body = "<?xml version='1.0' encoding='UTF-8'?>\r\n<ObjectList>\r\n";
foreach (['Musea Brugge','Museum voor Schone Kunsten Gent','Mu.ZEE, Kunstmuseum aan zee'] as $i=>$museum) {
    $body .= '<Object><priref>'.(901 + $i).'</priref><MuseumTxt>&lt;institution.name>'.$museum.'&lt;/institution.name></MuseumTxt><WebPublicationTxt>&lt;webpublication>Extranet&lt;/webpublication>&lt;webpublication>VKC datahub&lt;/webpublication></WebPublicationTxt><TitleTxt_nl>&lt;Title>&lt;title>Één &amp; twee&lt;/title>&lt;/Title></TitleTxt_nl><Empty></Empty></Object>'."\r\n";
}
$body .= "</ObjectList>\r\n";
$beforeFiles = glob($testArchive->directory().'/*.xml');
runImport($db, static fn (): MockResponse => new MockResponse($body));
$ids = [];
foreach ([901,902,903] as $id) {
    $row = $db->tables['records']['test:'.$id];
    $ids[] = $row['raw_response_id'];
    check($testArchive->read($row['raw_response_id']) === $body, 'Import did not retain exact raw bytes.');
    check($row['publish_vkc'] === 1 && $row['oai_xml_native'] !== null, 'Raw capture lost a mapped view.');
    check(str_contains($row['oai_xml'], 'Één &amp; twee'), 'Escaping repair did not reach mapped output.');
}
check(count(array_unique($ids)) === 1 && count(glob($testArchive->directory().'/*.xml')) === count($beforeFiles) + 1, 'The response must be archived once and shared by its records.');
$metadata = json_decode(file_get_contents($testArchive->directory().'/'.$ids[0].'.json'), true, flags: JSON_THROW_ON_ERROR);
check($metadata['offset'] === 0 && $metadata['limit'] === 1000, 'Capture lost request position.');

// Mapping/database failure leaves the captured source available for diagnosis.
$db = database(); $db->failInsertId = '1';
$beforeFiles = glob($testArchive->directory().'/*.xml');
runImport($db, static fn (): MockResponse => batch(['1']), [], 'record insert failure');
$savedFiles = array_values(array_diff(glob($testArchive->directory().'/*.xml'), $beforeFiles));
check(count($savedFiles) === 1 && $db->tables['records_import'] === [], 'Rollback removed the raw response or kept derived rows.');
check($testArchive->read(pathinfo($savedFiles[0], PATHINFO_FILENAME)) === '<ObjectList><Object><ID>1</ID></Object></ObjectList>', 'Rollback changed raw bytes.');

// Failing to write the metadata must stop before parsing/storage/checkpoint advancement.
$archive = App\Tests\Support\temporaryRawArchive();
$db = interruptedImport(); $before = $db->tables;
runImport($db, static function () use ($archive): MockResponse {
    $xml = glob($archive->directory().'/*.xml')[0];
    file_put_contents(substr($xml, 0, -4).'.json', 'occupied');
    return batch(['1']);
}, ['--resume'=>true], 'Cannot save raw response metadata', $archive);
check($db->tables === $before && !$db->locked, 'Archive failure modified records or left the lock held.');
echo "PASS: one exact source per batch linked to all museum/VKC outputs; raw bytes survive rollback; archive failure blocks storage\n";

$db = interruptedImport();
$firstRaw = $db->tables['records_import']['test:1']['raw_response_id'];
$original = $testArchive->read($firstRaw);
runImport($db, static fn (): MockResponse => batch(['3']), ['--resume'=>true]);
check($db->tables['records']['test:1']['raw_response_id'] === $firstRaw && $testArchive->read($firstRaw) === $original, 'Resume or publication changed the earlier raw source.');
check($db->tables['records']['test:3']['raw_response_id'] !== $firstRaw, 'Resumed batch reused the earlier capture.');
runImport($db, static fn (): MockResponse => batch(['4']), ['--restart'=>true]);
check($testArchive->read($firstRaw) === $original, 'Restart deleted the preceding import archive.');
echo "PASS: resume and publication retain raw links; restart preserves earlier archives\n";
