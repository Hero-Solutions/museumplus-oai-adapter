<?php

declare(strict_types=1);

// Run with: php -n tests/dump-response.php
// Only synthetic responses and a temporary directory within this project are used.

use App\Command\DumpMuseumPlusResponseCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

require dirname(__DIR__).'/vendor/autoload.php';

$_ENV = [
    'MUSEUMPLUS_EXPORT_URL' => 'https://museumplus.invalid/export',
    'MUSEUMPLUS_USERNAME' => 'fake-user',
    'MUSEUMPLUS_PASSWORD' => 'fake-password',
    'MUSEUMPLUS_SEARCH_FIELD_PATH' => 'test.field',
    'MUSEUMPLUS_SEARCH_OPERAND' => 'one & "two"',
    'MUSEUMPLUS_TIMEOUT_SECONDS' => '1',
];

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = dirname(__DIR__).'/var/test-dump-'.bin2hex(random_bytes(8));
check(mkdir($directory, 0700, true), 'Could not create isolated test directory.');
$truncatedXml = "<ObjectList>\r\n<Object><ID>fake-123</ID>\r\n<Tentoonstelling>";
$cases = [
    'invalid-xml' => [new MockResponse($truncatedXml), $truncatedXml, 0],
    'valid-xml' => [new MockResponse('<ObjectList/>'), '<ObjectList/>', 0],
    'http-error' => [new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]), '<html>Bad gateway</html>', 1],
    'empty' => [new MockResponse(''), '', 0],
    'transport-error' => [new MockResponse((static function () use ($truncatedXml): Generator {
        yield $truncatedXml;
        throw new TransportException('Simulated truncated transfer');
    })()), $truncatedXml, 1],
];

try {
    foreach ($cases as $name => [$response, $expected, $exitCode]) {
        $requests = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, &$requests): MockResponse {
            ++$requests;
            check($method === 'POST' && $url === 'https://museumplus.invalid/export', 'Unexpected request target.');
            check(str_contains($options['body'], '<search limit="1" offset="394807">'), 'Wrong offset or limit.');
            check(str_contains($options['body'], 'operand="one &amp; &quot;two&quot;"'), 'Search filter was not escaped.');

            return $response;
        });
        $tester = new CommandTester(new DumpMuseumPlusResponseCommand($client));
        $file = $directory.'/'.$name.'.xml';
        check($tester->execute(['offset' => '394807', 'file' => $file], ['interactive' => false]) === $exitCode, 'Wrong exit code for '.$name);
        check(file_get_contents($file) === $expected, 'Response bytes changed for '.$name);
        check($requests === 1, 'Dump retried or fetched extra objects.');
        check(str_contains($tester->getDisplay(), (string) strlen($expected)), 'Missing byte count.');
        fwrite(STDOUT, 'PASS: '.$name.' saved without parsing or changing the response'.PHP_EOL);
    }

    $client = new MockHttpClient(static function (): never {
        throw new LogicException('No request should be sent for an existing output file or invalid offset.');
    });
    $tester = new CommandTester(new DumpMuseumPlusResponseCommand($client));

    foreach ([['394807', 'invalid-xml.xml', 'Cannot create'], ['-1', 'new.xml', 'non-negative']] as [$offset, $filename, $expectedError]) {
        $error = null;

        try {
            $tester->execute(['offset' => $offset, 'file' => $directory.'/'.$filename], ['interactive' => false]);
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        check($error !== null && str_contains($error->getMessage(), $expectedError), 'Missing input protection.');
    }

    check(file_get_contents($directory.'/invalid-xml.xml') === $truncatedXml, 'Existing evidence was overwritten.');
    fwrite(STDOUT, "PASS: existing files and invalid offsets are protected before any request\n");
} finally {
    foreach (array_keys($cases) as $name) {
        $file = $directory.'/'.$name.'.xml';

        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($directory);
}
