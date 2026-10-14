<?php

declare(strict_types=1);

// php -n -d allow_url_fopen=0 tests/dump-export.php
// Only HTTP mocks, fake configuration and local synthetic files. No kernel or DB.

use App\Command\DumpMuseumPlusExportCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__).'/vendor/autoload.php';

$_ENV = [
    'MUSEUMPLUS_EXPORT_URL' => 'https://museumplus.invalid/export',
    'MUSEUMPLUS_USERNAME' => 'fake-user', 'MUSEUMPLUS_PASSWORD' => 'fake-password',
    'MUSEUMPLUS_SEARCH_FIELD_PATH' => 'test.field', 'MUSEUMPLUS_SEARCH_OPERAND' => 'one & "two"',
    'MUSEUMPLUS_ORG_UNITS' => "org_1_TestMuseum\norg_2_OtherMuseum", 'MUSEUMPLUS_TIMEOUT_SECONDS' => '1',
];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function capture(string $directory, array $responses, array $options = []): array
{
    $requests = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $responses): MockResponse {
        check($method === 'POST' && $url === 'https://museumplus.invalid/export', 'Unexpected request destination.');
        check(preg_match('/<search limit="(\d+)" offset="(\d+)">/', $options['body'], $match) === 1, 'Missing paging.');
        check(str_contains($options['body'], 'operand="one &amp; &quot;two&quot;"') && substr_count($options['body'], 'fieldPath="__orgUnit"') === 2, 'Raw capture lost the configured filters.');
        check(str_contains($options['body'], '<and>') && str_contains($options['body'], '<or>'), 'Incorrect filter combination.');
        check($options['buffer'] === false && $options['max_redirects'] === 0, 'Responses must stream without following redirects.');
        check(in_array('Accept-Encoding: identity', $options['headers'], true), 'Unexpected compression request.');
        $index = count($requests);
        $requests[] = [(int) $match[2], (int) $match[1]];
        check(isset($responses[$index]), 'Unexpected additional request or retry.');
        $response = $responses[$index];
        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response;
    });
    $tester = new CommandTester(new DumpMuseumPlusExportCommand($client));
    $status = $tester->execute(['directory' => $directory] + $options, ['interactive' => false]);
    $manifest = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($directory.'/manifest.jsonl', FILE_IGNORE_NEW_LINES));

    return [$status, $requests, $manifest, $tester->getDisplay()];
}

function streamed(string $body): MockResponse
{
    return new MockResponse((static function () use ($body): Generator {
        foreach (str_split($body, 17) as $chunk) {
            yield $chunk;
        }
    })());
}

$root = dirname(__DIR__).'/var/test-raw-export-'.bin2hex(random_bytes(8));
check(mkdir($root, 0700, true), 'Could not create test directory.');
$first = "\xEF\xBB\xBF<?xml version='1.0' encoding='UTF-8'?>\r\n<ObjectList>\r\n  <Object><priref>1</priref><CreatorTxt>&lt;Production>&lt;creator>A &amp; B&lt;/creator>&lt;/Production></CreatorTxt><Empty></Empty><Note><![CDATA[<Object>not a record</Object>]]></Note></Object>\r\n<Object><priref>2</priref><Empty/></Object>\r\n</ObjectList>\r\n";
$second = '<ObjectList><Object><priref>3</priref></Object></ObjectList>';
$empty = "<?xml version='1.0'?><ObjectList />\n";

try {
    [$status, $requests, $manifest, $output] = capture($root.'/complete', [streamed($first), streamed($second), streamed($empty)], ['--batch-size' => '3']);
    check($status === 0 && $requests === [[0, 3], [2, 3], [3, 3]], 'Capture stopped on a short page or advanced by the requested limit.');
    foreach ([$first, $second, $empty] as $index => $body) {
        $entry = $manifest[$index + 1];
        check(file_get_contents($root.'/complete/'.$entry['file']) === $body, 'Response bytes changed.');
        check($entry['bytes'] === strlen($body) && $entry['sha256'] === hash('sha256', $body) && $entry['transfer_complete'] === true, 'Incorrect response evidence.');
    }
    check(array_column(array_slice($manifest, 1), 'object_count') === [2, 1, 0] && $manifest[3]['result'] === 'complete', 'Object counting or completion marker failed.');
    check(str_contains($output, '3 objects captured'), 'Incorrect total.');
    $log = file_get_contents($root.'/complete/manifest.jsonl');
    check(!str_contains($log, 'fake-user') && !str_contains($log, 'fake-password') && !str_contains($log, 'museumplus.invalid'), 'Manifest contains credentials or URL.');
    fwrite(STDOUT, "PASS: every response byte preserved; real counts drive pagination through the final empty response\n");

    [$status, $requests, $manifest] = capture($root.'/continuation', [streamed($second), streamed($empty)], ['--batch-size' => '1', '--start-offset' => '42']);
    check($status === 0 && $requests === [[42, 1], [43, 1]] && $manifest[0]['start_offset'] === 42, 'Manual continuation used the wrong offsets.');
    check($manifest[0]['source_hash'] === json_decode(file($root.'/complete/manifest.jsonl')[0], true)['source_hash'], 'Source fingerprint depends on paging.');
    [$status, $requests, $manifest] = capture($root.'/empty', [streamed($empty)]);
    check($status === 0 && $requests === [[0, 1000]] && $manifest[1]['object_count'] === 0, 'Empty export did not finish.');
    fwrite(STDOUT, "PASS: start offset, default batch size and empty exports\n");

    $badBodies = [
        'http-error' => ['<html>Bad gateway</html>', 502],
        'redirect' => ['<html>Redirect</html>', 302],
        'partial-status' => ['<ObjectList/>', 206],
        'invalid-xml' => ['<ObjectList><Object><priref>7</priref>', 200],
        'html' => ['<html>Not an export</html>', 200],
        'empty-body' => ['', 200],
        'error-child' => ['<ObjectList><Error>Failure</Error></ObjectList>', 200],
        'error-text' => ['<ObjectList>Failure</ObjectList>', 200],
        'doctype' => ['<!DOCTYPE ObjectList [<!ENTITY x "example">]><ObjectList/>', 200],
        'ignored-limit' => ['<ObjectList><Object/><Object/></ObjectList>', 200],
    ];
    foreach ($badBodies as $name => [$body, $httpStatus]) {
        [$status, $requests, $manifest] = capture($root.'/'.$name, [new MockResponse($body, ['http_code' => $httpStatus])], ['--batch-size' => '1']);
        check($status === 1 && $requests === [[0, 1]], 'Invalid response was retried or treated as complete: '.$name);
        $entry = $manifest[1];
        check($entry['result'] === 'error' && $entry['next_offset'] === 0 && $entry['http_status'] === $httpStatus, 'Invalid response advanced the offset: '.$name);
        check(file_get_contents($root.'/'.$name.'/'.$entry['file']) === $body, 'Invalid response was repaired or discarded: '.$name);
    }
    fwrite(STDOUT, "PASS: HTTP failures, redirects, malformed/error responses and ignored limits stay intact and stop the capture\n");

    $partial = '<ObjectList><Object><priref>99</priref>';
    $interrupted = new MockResponse((static function () use ($partial): Generator {
        yield $partial;
        throw new TransportException('Simulated disconnect');
    })());
    [$status, $requests, $manifest] = capture($root.'/transport', [streamed($second), $interrupted], ['--batch-size' => '1']);
    $entry = $manifest[2];
    check($status === 1 && $requests === [[0, 1], [1, 1]] && $entry['next_offset'] === 1 && !$entry['transfer_complete'], 'Partial transfer advanced or retried.');
    check(file_get_contents($root.'/transport/'.$entry['file']) === $partial && $entry['sha256'] === hash('sha256', $partial), 'Received partial bytes were lost.');
    foreach (['connect-failure' => new TransportException('Connection failed'), 'header-failure' => new MockResponse('', ['error' => 'Connection failed'])] as $name => $failure) {
        [$status, $requests, $manifest] = capture($root.'/'.$name, [$failure]);
        check($status === 1 && count($requests) === 1 && $manifest[1]['bytes'] === 0 && !$manifest[1]['transfer_complete'], 'Failure before body was not recorded.');
    }
    [$status, $requests, $manifest] = capture($root.'/repeated', [streamed($second), streamed($second)]);
    check($status === 1 && count($requests) === 2 && $manifest[2]['next_offset'] === 1, 'Repeated response caused an infinite paging loop.');
    check(file_get_contents($root.'/repeated/'.$manifest[2]['file']) === $second, 'Repeated response evidence lost.');
    fwrite(STDOUT, "PASS: partial transfers, failures before a body and repeated batches are retained without retries or skipped offsets\n");

    $client = new MockHttpClient(static function (): never {
        throw new LogicException('Invalid input must not send a request.');
    });
    $tester = new CommandTester(new DumpMuseumPlusExportCommand($client));
    foreach ([
        ['directory' => $root.'/complete'],
        ['directory' => 'https://example.invalid/output'],
        ['directory' => $root.'/invalid', '--batch-size' => '0'],
        ['directory' => $root.'/invalid', '--start-offset' => '-1'],
        ['directory' => $root.'/invalid', '--start-offset' => '99999999999999999999999'],
    ] as $input) {
        $caught = null;
        try {
            $tester->execute($input);
        } catch (RuntimeException $error) {
            $caught = $error;
        }
        check($caught !== null, 'Invalid input accepted.');
    }
    check(file_get_contents($root.'/complete/offset-000000000000.xml') === $first, 'Existing capture was overwritten.');
    check(!file_exists($root.'/invalid'), 'Invalid input created an archive.');
    fwrite(STDOUT, "PASS: existing archives and invalid arguments are protected before requests\n");
} finally {
    // Only this test's freshly created directory and its known output files.
    foreach (glob($root.'/*', GLOB_ONLYDIR) as $directory) {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
    rmdir($root);
}
