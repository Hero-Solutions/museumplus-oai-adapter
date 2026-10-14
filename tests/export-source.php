<?php

declare(strict_types=1);

// php -n -d allow_url_fopen=0 tests/export-source.php
// Synthetic in-memory data only. No kernel, .env, driver or HTTP client.

use App\Command\ExportMuseumPlusSourceCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__).'/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class SourceExportConnection extends Connection
{
    public array $tables = ['records' => [], 'records_import' => []];
    public array $queries = [];
    public bool $locked = false;
    public bool $failRead = false;
    public int $lockAttempts = 0;

    public function __construct()
    {
        // No driver/DSN/parent setup; unhandled DB methods cannot connect.
    }

    protected function connect(): DriverConnection
    {
        throw new LogicException('Real database connections are forbidden in this test.');
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if ($query === "SELECT GET_LOCK(CONCAT('museumplus-import:', LEFT(SHA2(DATABASE(), 256), 32)), 0)") {
            ++$this->lockAttempts;
            if ($this->locked) {
                return 0;
            }
            $this->locked = true;

            return 1;
        }
        if ($query === "SELECT RELEASE_LOCK(CONCAT('museumplus-import:', LEFT(SHA2(DATABASE(), 256), 32)))") {
            $this->locked = false;

            return 1;
        }
        throw new LogicException('Unexpected fetchOne: '.$query);
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        check($this->locked, 'Read without the import lock.');
        check(preg_match('/^SELECT id, museumplus_xml FROM (records|records_import) WHERE id > \? ORDER BY id LIMIT 100$/D', $query, $match) === 1, 'Unexpected source query.');
        $this->queries[] = [$match[1], $params[0]];
        if ($this->failRead) {
            throw new RuntimeException('Simulated read failure');
        }
        $rows = array_filter($this->tables[$match[1]], static fn (array $row): bool => $row['id'] > $params[0]);
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($rows, 0, 100);
    }
}

function expectFailure(CommandTester $tester, array $input, string $expected): void
{
    $error = null;
    try {
        $tester->execute($input, ['interactive' => false]);
    } catch (RuntimeException $exception) {
        $error = $exception;
    }
    check($error !== null && str_contains($error->getMessage(), $expected), 'Missing expected error: '.$expected);
}

$directory = dirname(__DIR__).'/var/test-source-export-'.bin2hex(random_bytes(8));
check(mkdir($directory, 0700, true), 'Could not create isolated test directory.');
$header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ObjectList>\n";
$footer = "</ObjectList>\n";
$db = new SourceExportConnection();
// Deliberately preserve whitespace, entity spellings, CDATA and an invalid inner fragment.
$first = "  <Object>\r\n<priref>fake-1</priref><CreatorTxt>&lt;Production>&lt;creator>Alpha &amp; Beta&lt;/creator>&lt;/Production></CreatorTxt><Empty></Empty><NotesTxt><![CDATA[<notes>A & B</notes>]]></NotesTxt></Object>  ";
$expected = $header;
for ($i = 1; $i <= 205; ++$i) {
    $source = $i === 1 ? $first : '<Object><priref>fake-'.$i.'</priref><Empty/></Object>';
    $db->tables['records'][] = ['id' => $i * 3, 'museumplus_xml' => $source];
    $expected .= $source."\n";
}
$expected .= $footer;
$db->tables['records'] = array_reverse($db->tables['records']);
$db->tables['records_import'] = [['id' => 9, 'museumplus_xml' => '<Object><ID>staging</ID></Object>']];
$saved = $db->tables;
$tester = new CommandTester(new ExportMuseumPlusSourceCommand($db));

try {
    check($tester->execute(['file' => $directory.'/all.xml']) === 0, 'Export failed.');
    check(file_get_contents($directory.'/all.xml') === $expected, 'Source bytes, order or wrapper changed.');
    check($db->queries === [['records', 0], ['records', 300], ['records', 600], ['records', 615]], 'Batches skipped or duplicated records.');
    check(!$db->locked && $db->tables === $saved, 'Export retained a lock or modified data.');
    check(str_contains($tester->getDisplay(), '205 records'), 'Missing record count.');
    fwrite(STDOUT, "PASS: all records exported in bounded batches, exact source bytes preserved, no database changes\n");

    check($tester->execute(['file' => $directory.'/staging.xml', '--table' => 'records_import']) === 0, 'Staging export failed.');
    check(file_get_contents($directory.'/staging.xml') === $header.$saved['records_import'][0]['museumplus_xml']."\n".$footer, 'Staging mixed with published records.');
    $db->tables['records_import'] = [];
    check($tester->execute(['file' => $directory.'/empty.xml', '--table' => 'records_import']) === 0, 'Empty export failed.');
    check(file_get_contents($directory.'/empty.xml') === $header.$footer, 'Empty wrapper is incorrect.');
    fwrite(STDOUT, "PASS: explicit staging selection and empty database exports\n");

    $attempts = $db->lockAttempts;
    expectFailure($tester, ['file' => $directory.'/all.xml'], 'Cannot create');
    expectFailure($tester, ['file' => $directory.'/invalid.xml', '--table' => 'records; DROP TABLE records'], '--table must be');
    expectFailure($tester, ['file' => 'https://example.invalid/export.xml'], 'local output');
    check($db->lockAttempts === $attempts && file_get_contents($directory.'/all.xml') === $expected, 'Invalid input touched the database or overwrote a file.');
    fwrite(STDOUT, "PASS: existing files, invalid tables and nonlocal destinations rejected before database access\n");

    $db->locked = true;
    $queries = $db->queries;
    expectFailure($tester, ['file' => $directory.'/locked.xml'], 'already running');
    check($db->locked && $db->queries === $queries && !file_exists($directory.'/locked.xml'), 'Active import protection failed.');
    $db->locked = false;
    $db->failRead = true;
    expectFailure($tester, ['file' => $directory.'/failed.xml'], 'Simulated read failure');
    check(!$db->locked && !file_exists($directory.'/failed.xml'), 'Failed read left a lock or incomplete output.');
    $db->failRead = false;
    $db->tables['records_import'] = [$saved['records_import'][0], ['id' => 10, 'museumplus_xml' => '']];
    expectFailure($tester, ['file' => $directory.'/missing.xml', '--table' => 'records_import'], 'Missing museumplus_xml');
    check(!$db->locked && !file_exists($directory.'/missing.xml'), 'Missing source was silently skipped or left partial output.');
    fwrite(STDOUT, "PASS: concurrent import, read failures and missing source cannot produce a successful partial export\n");
} finally {
    foreach (['all', 'staging', 'empty', 'invalid', 'locked', 'failed', 'missing'] as $name) {
        $file = $directory.'/'.$name.'.xml';
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}
