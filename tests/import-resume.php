<?php

declare(strict_types=1);

// Run with: php -n tests/import-resume.php
// No kernel/.env, database driver or real HTTP client. All settings are fake.

use App\Axiell\AxiellRecordMapper;
use App\Axiell\AxiellXmlRenderer;
use App\Command\ImportMuseumPlusRecordsCommand;
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

$_ENV = [
    'MUSEUMPLUS_EXPORT_URL' => 'https://museumplus.invalid/export',
    'MUSEUMPLUS_USERNAME' => 'fake-user',
    'MUSEUMPLUS_PASSWORD' => 'fake-password',
    'MUSEUMPLUS_SEARCH_FIELD_PATH' => 'test.field',
    'MUSEUMPLUS_SEARCH_OPERAND' => 'test-value',
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
function runImport(MemoryImportConnection $db, callable $factory, array $options = [], ?string $expectedError = null): array
{
    $requests = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $factory): MockResponse {
        check($url === 'https://museumplus.invalid/export' && $method === 'POST', 'Only the fake export may be requested.');
        preg_match('/limit="(\d+)" offset="(\d+)"/', $options['body'], $match);
        $offset = (int) $match[2];
        $limit = (int) $match[1];
        $requests[] = [$offset, $limit];

        return $factory($offset, $limit);
    });
    $command = new ImportMuseumPlusRecordsCommand(
        $client,
        new MuseumPlusExportParser(new XmlValueExtractor()),
        new AxiellRecordMapper(new CreatorNormalizer(), new DimensionNormalizer(), new LabelNormalizer()),
        new AxiellXmlRenderer(new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'))),
        $db,
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
runImport($db, noFetch(...), ['--resume' => true, '--start-offset' => '3'], 'Saved next offset is 2');
runImport($db, static fn (): MockResponse => new MockResponse('<html><body>Offline</body></html>'), ['--resume' => true], 'Expected an ObjectList');
runImport($db, static fn (): MockResponse => new MockResponse('<ObjectList><Object>'), ['--resume' => true], 'Could not parse');
check($db->tables === $saved, 'Bad configuration or invalid XML changed saved data.');
unset($db->tables['records_import']['test:2']);
runImport($db, noFetch(...), ['--resume' => true], 'does not match');
fwrite(STDOUT, "PASS: changed filter, wrong offset, error pages, broken XML and mismatched staging are rejected\n");

$db = interruptedImport();
$db->tables['museumplus_import_state'][1]['next_offset'] = 394000;
$db->tables['museumplus_import_state'][1]['skipped'] = 393998;
$saved = $db->tables;
$truncatedXml = '<ObjectList><Object><ID>discarded</ID></Object><Object><ID>3</ID><Tentoonstelling>';
$requests = runImport($db, static fn (): MockResponse => new MockResponse($truncatedXml), ['--resume' => true], 'after 4 attempts');
check($requests === array_fill(0, 4, [394000, 1000]) && $db->tables === $saved, 'Repeated truncated XML changed the checkpoint or stored partial records.');
check(!$db->locked && $db->renames === 0, 'Truncated XML left a lock or published incomplete data.');
$attempt = 0;
$requests = runImport($db, static function () use ($db, $saved, $truncatedXml, &$attempt): MockResponse {
    check($db->tables === $saved, 'A partial XML document was stored before the retry completed.');

    return ++$attempt === 1 ? new MockResponse($truncatedXml) : batch(['3']);
}, ['--resume' => true]);
check($requests === [[394000, 1000], [394000, 1000]], 'Resume refetched earlier batches or skipped the failed offset.');
check(array_keys($db->tables['records']) === ['test:1', 'test:2', 'test:3'], 'Recovered batch contains discarded partial records.');
check($db->tables['museumplus_import_state'][1]['next_offset'] === 394001 && $db->renames === 1, 'Recovered batch advanced or published incorrectly.');
fwrite(STDOUT, "PASS: truncated XML at offset 394000 retries, preserves progress and resumes without partial records\n");

$db = interruptedImport();
$db->tables['museumplus_import_state'] = [];
$saved = $db->tables;
runImport($db, noFetch(...), [], 'already contains data');
runImport($db, noFetch(...), ['--resume' => true], 'No saved checkpoint');
runImport($db, noFetch(...), ['--resume' => true, '--start-offset' => '1'], 'No saved checkpoint');
check($db->tables === $saved, 'Legacy staging data was discarded.');
$requests = runImport($db, static fn (): MockResponse => batch(['3']), ['--resume' => true, '--start-offset' => '309000']);
check($requests === [[309000, 1000]] && count($db->tables['records']) === 3, 'Legacy recovery lost data or used the wrong offset.');
fwrite(STDOUT, "PASS: legacy staging is preserved and adopted at the supplied failed offset\n");

$db = interruptedImport();
$requests = runImport($db, static fn (): MockResponse => batch(['9']), ['--restart' => true]);
check($requests === [[0, 1000]] && array_keys($db->tables['records']) === ['test:9'], 'Explicit restart did not reset the staging import.');
fwrite(STDOUT, "PASS: explicit restart discards the unfinished import and starts from zero\n");

$db = database();
runImport($db, static fn (int $offset): MockResponse => $offset === 0 ? batch(['1', '']) : new MockResponse('Unauthorized', ['http_code' => 401]), ['--batch-size' => '2'], 'HTTP 401');
check(count($db->tables['records_import']) === 1 && $db->tables['museumplus_import_state'][1]['next_offset'] === 2, 'Offset was derived from stored row count instead of fetched records.');
runImport($db, static fn (): MockResponse => batch([]), ['--resume' => true]);
check(count($db->tables['records']) === 1, 'Empty last page prevented publishing an earlier batch.');
fwrite(STDOUT, "PASS: skipped records advance the offset and an empty final page publishes saved batches\n");

$db = database();
runImport($db, static fn (): MockResponse => batch([]), [], 'zero records');
check(array_keys($db->tables['records']) === ['test:old'], 'Empty import replaced published records.');
runImport($db, noFetch(...), ['--resume' => true, '--allow-empty-swap' => true]);
check($db->tables['records'] === [], 'Explicit empty swap did not work.');
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
$staging = $db->tables['records_import'];
$state = $db->tables['museumplus_import_state'];
runImport($db, static fn (): MockResponse => batch(['sample']), ['--max-records' => '1']);
check(isset($db->tables['records']['test:sample']) && $db->tables['records_import'] === $staging && $db->tables['museumplus_import_state'] === $state, 'Partial import modified the full import checkpoint.');
foreach ([['--resume' => true, '--max-records' => '1'], ['--resume' => true, '--restart' => true], ['--restart' => true, '--start-offset' => '1']] as $options) {
    runImport($db, noFetch(...), $options, 'cannot');
}
$db->locked = true;
runImport($db, noFetch(...), ['--resume' => true], 'already running');
fwrite(STDOUT, "PASS: partial imports, incompatible options and concurrent import protection\n");

$db = interruptedImport();
$db->tables['museumplus_import_state'][1]['next_offset'] = 394807;
$db->tables['museumplus_import_state'][1]['skipped'] = 394805;
$requests = runImport($db, static fn (int $offset): MockResponse => $offset === 394808 ? batch(['next-object']) : batch([]), [
    '--resume' => true, '--skip-offset' => '394807', '--batch-size' => '1',
]);
check($requests === [[394808, 1], [394809, 1]], 'Explicit skip did not fetch exactly the following source position.');
check(array_keys($db->tables['records']) === ['test:1', 'test:2', 'test:next-object'], 'Skip lost previous records or the next object.');
check($db->tables['museumplus_import_state'][1]['skipped'] === 394806, 'Exactly one skipped record must be counted.');
runImport($db, noFetch(...), ['--resume' => true, '--skip-offset' => '394807'], 'no longer fetching');
fwrite(STDOUT, "PASS: explicit skip of 394807 preserves earlier records and imports 394808\n");

$db = interruptedImport();
$staging = $db->tables['records_import'];
runImport($db, static fn (): MockResponse => new MockResponse('Unauthorized', ['http_code' => 401]), [
    '--resume' => true, '--skip-offset' => '2', '--batch-size' => '1',
], 'HTTP 401');
check($db->tables['museumplus_import_state'][1]['next_offset'] === 3 && $db->tables['museumplus_import_state'][1]['skipped'] === 1, 'Skip was lost when the following request failed.');
check($db->tables['records_import'] === $staging && array_keys($db->tables['records']) === ['test:old'], 'Skipping changed stored records.');
$saved = $db->tables;
runImport($db, noFetch(...), ['--resume' => true, '--skip-offset' => '2'], 'Saved next offset is 3');
check($db->tables === $saved, 'Reusing the skip command skipped another object.');
check(runImport($db, static fn (): MockResponse => batch(['3']), ['--resume' => true]) === [[3, 1000]], 'Normal resume did not retain the deliberate skip.');
fwrite(STDOUT, "PASS: skip persists across another failure and cannot accidentally be applied twice\n");

$db = interruptedImport();
$saved = $db->tables;
runImport($db, noFetch(...), ['--resume' => true, '--skip-offset' => '5'], 'Saved next offset is 2');
foreach ([['--skip-offset' => '2'], ['--resume' => true, '--skip-offset' => '2', '--start-offset' => '2']] as $options) {
    runImport($db, noFetch(...), $options, 'requires --resume');
}
runImport($db, noFetch(...), ['--resume' => true, '--skip-offset' => '-1'], 'non-negative');
check($db->tables === $saved, 'Rejected skip changed the checkpoint.');
$db->failCheckpoint = true;
runImport($db, noFetch(...), ['--resume' => true, '--skip-offset' => '2'], 'checkpoint failure');
check($db->tables === $saved && !$db->locked, 'A failed skip checkpoint was not rolled back.');
$db->failCheckpoint = false;
$db->tables['museumplus_import_state'][1]['status'] = 'ready';
runImport($db, noFetch(...), ['--resume' => true, '--skip-offset' => '2'], 'no longer fetching');
runImport(database(), noFetch(...), ['--resume' => true, '--skip-offset' => '0'], 'No saved checkpoint');
fwrite(STDOUT, "PASS: wrong offset, invalid options, missing checkpoint and failed checkpoint writes cannot skip records\n");

$db = database();
runImport($db, static fn (): MockResponse => new MockResponse('Unauthorized', ['http_code' => 401]), [], 'HTTP 401');
$requests = runImport($db, static fn (): MockResponse => batch(['first-valid']), ['--resume' => true, '--skip-offset' => '0']);
check($requests === [[1, 1000]] && $db->tables['museumplus_import_state'][1]['skipped'] === 1, 'Offset zero could not be skipped explicitly.');
fwrite(STDOUT, "PASS: the very first offset can also be skipped explicitly\n");

function brokenObject(int $id): string
{
    return '<ObjectList><Object><ID>'.$id.'</ID><Tentoonstelling>unfinished';
}

/** Simulate an export that truncates exactly when it reaches one broken object. */
function damagedExport(int $end, int $brokenOffset): Closure
{
    return static function (int $offset, int $limit) use ($end, $brokenOffset): MockResponse {
        $xml = '<ObjectList>';
        for ($position = $offset; $position < min($end, $offset + $limit); ++$position) {
            $xml .= '<Object><ID>'.($position + 1).'</ID>';
            if ($position === $brokenOffset) {
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
    [394807, 1], [394807, 1], [394807, 1], [394807, 1], [394808, 8],
], 'Isolation did not halve at the same offset, retry the singleton or restore the batch size.');
check(count($db->tables['records']) === 13 && !isset($db->tables['records']['test:394808']), 'Isolation lost valid records or imported the broken object.');
check(isset($db->tables['records']['test:1'], $db->tables['records']['test:394812']), 'Earlier staging data or the final object was lost.');
$state = $db->tables['museumplus_import_state'][1];
check($state['next_offset'] === 394812 && $state['skipped'] === 1 && $state['status'] === 'completed', 'Automatic skip checkpoint/count was incorrect.');
$log = $db->tables['museumplus_import_errors'][1];
check($log['source_offset'] === 394807 && $log['museumplus_id'] === '394808' && $log['response_body'] === brokenObject(394808), 'Error log lost the exact offset, ID or raw bytes.');
check($log['source_hash'] === $state['source_hash'] && $log['import_datestamp'] === $state['datestamp'] && str_contains($log['error_message'], 'after 4 attempts'), 'Error log lacks import context or the error.');
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
$db = database();
$attempt = 0;
runImport($db, static function (int $offset) use (&$attempt): MockResponse {
    return ++$attempt === 1 ? new MockResponse(brokenObject(1)) : ($offset === 0 ? batch(['1']) : batch([]));
}, ['--batch-size' => '1']);
check(isset($db->tables['records']['test:1']) && $db->tables['museumplus_import_errors'] === [], 'Transient singleton truncation was skipped.');
fwrite(STDOUT, "PASS: transient invalid XML recovers without skipping, including odd batch sizes\n");

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
$requests = runImport($db, static fn (): MockResponse => batch(['4']), ['--resume' => true]);
check($requests === [[3, 1000]] && count($db->tables['museumplus_import_errors']) === 1, 'Resume revisited or double-logged the skipped object.');
fwrite(STDOUT, "PASS: automatic skip survives interruption and resume never replays it\n");

foreach (['changing XML', 'HTTP then XML', 'transport then XML', 'HTML', 'empty', 'multiple objects'] as $failure) {
    $db = interruptedImport();
    $saved = $db->tables;
    $attempt = 0;
    $requests = runImport($db, static function () use ($failure, &$attempt): MockResponse {
        ++$attempt;
        if ($attempt === 1 && $failure === 'transport then XML') {
            throw new Symfony\Component\HttpClient\Exception\TransportException('Simulated interruption');
        }

        return match ($failure) {
            'changing XML' => new MockResponse(brokenObject(3).$attempt),
            'HTTP then XML' => $attempt === 1 ? new MockResponse('Unavailable', ['http_code' => 503]) : new MockResponse(brokenObject(3)),
            'HTML' => new MockResponse('<html><body>Service unavailable</body></html>'),
            'empty' => new MockResponse(''),
            'multiple objects' => new MockResponse('<ObjectList><Object><ID>3</ID></Object><Object><ID>4</ID><Broken>'),
            default => new MockResponse(brokenObject(3)),
        };
    }, ['--resume' => true, '--batch-size' => '1'], 'after 4 attempts');
    check($db->tables === $saved && $requests === array_fill(0, 4, [2, 1]), $failure.': uncertain failure skipped a record.');
}
fwrite(STDOUT, "PASS: changing/mixed failures, error pages, empty responses and ignored limits never auto-skip\n");

$db = interruptedImport();
$requests = runImport($db, static fn (int $offset): MockResponse => new MockResponse(brokenObject($offset + 1)), ['--resume' => true, '--batch-size' => '1'], 'Already skipped 3 consecutive');
check($db->tables['museumplus_import_state'][1]['next_offset'] === 5 && $db->tables['museumplus_import_state'][1]['consecutive_invalid'] === 3 && count($db->tables['museumplus_import_errors']) === 3, 'Consecutive invalid objects were not capped.');
$saved = $db->tables;
$requests = runImport($db, static fn (): MockResponse => new MockResponse(brokenObject(6)), ['--resume' => true, '--batch-size' => '1'], 'Already skipped 3 consecutive');
check($db->tables === $saved && $requests === array_fill(0, 4, [5, 1]), 'Resuming bypassed the consecutive error limit.');
runImport($db, static fn (int $offset): MockResponse => match ($offset) {
    5 => batch(['6']),
    6 => new MockResponse(brokenObject(7)),
    default => batch([]),
}, ['--resume' => true, '--batch-size' => '1']);
check($db->tables['museumplus_import_state'][1]['skipped'] === 4 && count($db->tables['museumplus_import_errors']) === 4, 'Healthy response did not reset the consecutive error limit.');
fwrite(STDOUT, "PASS: consecutive skip limit persists across resume and resets after a valid response\n");

$db = interruptedImport();
$savedState = $db->tables['museumplus_import_state'];
$savedStaging = $db->tables['records_import'];
$requests = runImport($db, damagedExport(20, 11), ['--start-offset' => '10', '--max-records' => '3', '--batch-size' => '8']);
check($requests === [[10, 3], [10, 1], [11, 1], [11, 1], [11, 1], [11, 1], [12, 1]], 'Partial import exceeded the source-position limit or skipped a valid record.');
check(array_keys($db->tables['records']) === ['test:old', 'test:11', 'test:13'] && count($db->tables['museumplus_import_errors']) === 1, 'Partial import skip/store/log was incorrect.');
check($db->tables['museumplus_import_state'] === $savedState && $db->tables['records_import'] === $savedStaging, 'Partial isolation modified the full import.');
fwrite(STDOUT, "PASS: partial imports obey max-records even with auto-skips and preserve full import progress\n");
