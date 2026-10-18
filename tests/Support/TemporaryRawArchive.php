<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Import\RawResponseArchive;
use RuntimeException;

/** Only synthetic files in a new, owned directory inside this project's var/. */
function temporaryRawArchive(): RawResponseArchive
{
    $parent = realpath(dirname(__DIR__, 2).'/var');
    if ($parent === false) throw new RuntimeException('Missing local test var directory.');
    $directory = $parent.'/test-raw-'.bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create test archive.');
    register_shutdown_function(static function () use ($directory, $parent): void {
        $resolved = realpath($directory);
        if ($resolved === false || dirname($resolved) !== $parent || is_link($directory)) return;
        // No recursive removal: these are only the flat files owned by this fixture.
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file) && !is_link($file)) unlink($file);
        }
        rmdir($directory);
    });

    return new RawResponseArchive($directory);
}
