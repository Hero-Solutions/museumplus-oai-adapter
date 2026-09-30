<?php

declare(strict_types=1);

// Run with: php -n tests/import-transport.php
// No application bootstrap, environment configuration, real HTTP client or database.

use App\Command\ImportMuseumPlusRecordsCommand;
use App\Mapping\InvalidMuseumPlusResponse;
use App\Mapping\MuseumPlusExportParser;
use App\Mapping\XmlValueExtractor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

require dirname(__DIR__).'/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function interruptedBody(): MockResponse
{
    return new MockResponse((static function (): Generator {
        yield '<ObjectList><Object>';
        throw new TransportException('OpenSSL: unexpected eof while reading');
    })());
}

$xml = '<ObjectList><Object><ID>123</ID></Object></ObjectList>';
$truncatedXml = '<ObjectList><Object><ID>discarded</ID></Object><Object><ID>123</ID><Tentoonstelling>';
$cases = [
    'successful response' => [[new MockResponse($xml)], null],
    'interrupted body is fetched again in full' => [[interruptedBody(), new MockResponse($xml)], null],
    'failure before a response exists' => [[new TransportException('Connection failed'), new MockResponse($xml)], null],
    'failure while reading headers' => [[new MockResponse('', ['error' => 'Connection closed']), new MockResponse($xml)], null],
    'transport failure followed by HTTP 502 recovers' => [[interruptedBody(), new MockResponse('Bad gateway', ['http_code' => 502]), new MockResponse($xml)], null],
    'persistent failure stops after four attempts' => [[interruptedBody(), interruptedBody(), interruptedBody(), interruptedBody()], 'after 4 attempts'],
    'persistent HTTP 502 stops after four attempts' => [[
        new MockResponse('Bad gateway', ['http_code' => 502]),
        new MockResponse('Bad gateway', ['http_code' => 502]),
        new MockResponse('Bad gateway', ['http_code' => 502]),
        new MockResponse('Bad gateway', ['http_code' => 502]),
    ], 'HTTP 502 at offset 309000 after 4 attempts'],
    'mixed failures share one retry budget' => [[
        interruptedBody(),
        new MockResponse('Bad gateway', ['http_code' => 502]),
        interruptedBody(),
        new MockResponse('Service unavailable', ['http_code' => 503]),
    ], 'HTTP 503 at offset 309000 after 4 attempts'],
    'HTTP error is not retried' => [[new MockResponse('Unauthorized', ['http_code' => 401])], 'HTTP 401'],
    'forbidden response is not retried' => [[new MockResponse('Forbidden', ['http_code' => 403])], 'HTTP 403'],
    'missing export is not retried' => [[new MockResponse('Not found', ['http_code' => 404])], 'HTTP 404'],
    'empty response recovers' => [[new MockResponse('   '), new MockResponse($xml)], null],
    'truncated XML with HTTP 200 is fetched again in full' => [[new MockResponse($truncatedXml), new MockResponse($xml)], null],
    'HTTP 502 followed by truncated XML recovers' => [[new MockResponse('Bad gateway', ['http_code' => 502]), new MockResponse($truncatedXml), new MockResponse($xml)], null],
    'persistent invalid XML stops after four attempts' => [[new MockResponse($truncatedXml), new MockResponse($truncatedXml), new MockResponse($truncatedXml), new MockResponse($truncatedXml)], 'after 4 attempts'],
    'transport, HTTP and XML failures share one retry budget' => [[interruptedBody(), new MockResponse('Bad gateway', ['http_code' => 502]), new MockResponse($truncatedXml), new MockResponse('   ')], 'after 4 attempts'],
];

foreach ([500, 502, 503, 504] as $statusCode) {
    $cases['HTTP '.$statusCode.' recovers'] = [[new MockResponse('Temporary error', ['http_code' => $statusCode]), new MockResponse($xml)], null];
}

foreach ($cases as $name => [$responses, $expectedError]) {
    $requests = [];
    $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $responses): MockResponse {
        $index = count($requests);
        $requests[] = [$method, $url, $options];
        check(isset($responses[$index]), 'Unexpected additional request.');
        $response = $responses[$index];

        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response;
    });

    // Exercise fetching and parsing; the command cannot read configuration or use a database.
    $reflection = new ReflectionClass(ImportMuseumPlusRecordsCommand::class);
    $command = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('httpClient')->setValue($command, $client);
    $reflection->getProperty('parser')->setValue($command, new MuseumPlusExportParser(new XmlValueExtractor()));
    $output = new BufferedOutput();
    $io = new SymfonyStyle(new ArrayInput([]), $output);
    $error = null;

    try {
        $records = $reflection->getMethod('fetchBatch')->invoke(
            $command,
            'https://museumplus.invalid/export',
            'fake-user',
            'fake-password',
            'test.field',
            'test-value',
            100,
            309000,
            30,
            $io,
        );
    } catch (RuntimeException $exception) {
        $error = $exception;
    }

    if ($expectedError === null) {
        check($error === null, $name.': unexpected failure: '.($error?->getMessage() ?? ''));
        check(count($records) === 1 && $records[0]->museumplusId === '123', $name.': incomplete or duplicated records.');
        check($records[0]->museumplusXml === '<Object><ID>123</ID></Object>', $name.': incomplete or changed record XML.');
    } else {
        check($error !== null && str_contains($error->getMessage(), $expectedError), $name.': missing expected failure.');
        check(str_contains($error->getMessage(), '309000'), $name.': missing failed offset.');
    }

    if ($expectedError === 'after 4 attempts') {
        $previous = $error->getPrevious();
        check($previous instanceof TransportExceptionInterface || $previous instanceof InvalidMuseumPlusResponse, 'Original fetch or parsing error must be preserved.');
    }

    check(count($requests) === count($responses), $name.': wrong number of attempts.');

    foreach ($requests as [$method, $url, $options]) {
        check($method === 'POST' && $url === 'https://museumplus.invalid/export', 'Request target changed.');
        check(str_contains($options['body'], 'limit="100" offset="309000"'), 'Retry skipped or changed the failed batch.');
        check($options['body'] === $requests[0][2]['body'], 'Retry changed the search criteria.');
        check(($options['verify_peer'] ?? true) && ($options['verify_host'] ?? true), 'TLS verification must stay enabled.');
    }

    $display = preg_replace('/\s+/', ' ', $output->fetch());
    check(substr_count($display, 'Retrying the same batch') === count($requests) - 1, $name.': missing retry notice.');
    fwrite(STDOUT, 'PASS: '.$name.PHP_EOL);
}
