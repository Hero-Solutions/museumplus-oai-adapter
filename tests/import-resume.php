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
