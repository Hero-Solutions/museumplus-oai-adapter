<?php

declare(strict_types=1);

// Separate PHP processes prevent shared fixtures/functions between suites.
// These suites use local files and mocks, without the application kernel or .env.
$suites = [
    'import-schema',
    'search-filter', 'xml-fragments', 'export-format', 'profiles',
    'reference-mapping', 'raw-archive', 'rebuild-records', 'harvest-query', 'oai-profiles', 'import-resume',
];

foreach ($suites as $suite) {
    echo "\n".$suite."\n";
    $process = proc_open(
        [PHP_BINARY, '-n', '-d', 'allow_url_fopen=0', __DIR__.'/'.$suite.'.php'],
        [STDIN, STDOUT, STDERR],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process) || proc_close($process) !== 0) {
        fwrite(STDERR, 'FAILED: '.$suite."\n");
        exit(1);
    }
}

echo "\nPASS: ".count($suites)." local test suites\n";
