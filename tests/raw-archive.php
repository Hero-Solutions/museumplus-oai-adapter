<?php

declare(strict_types=1);

// Synthetic HTTP streams and local files only. No kernel, credentials or live clients.
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/TemporaryRawArchive.php';

use App\Import\RawResponseArchive;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fails(callable $call, string $message): void {
    try { $call(); } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $message), 'Unexpected failure: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: '.$message);
}
$archive = App\Tests\Support\temporaryRawArchive();
$url = 'https://museumplus.invalid/export';
$options = ['buffer'=>false, 'auth_basic'=>['fake-user','fake-password'], 'headers'=>['Accept-Encoding'=>'identity']];
$context = ['source_hash'=>str_repeat('a', 64), 'import_datestamp'=>'2026-10-18 00:00:00','offset'=>0,'limit'=>1000];
$body = "\xEF\xBB\xBF<?xml version='1.0' encoding='UTF-8'?>\r\n<ObjectList><!-- original -->\r\n<Object><priref>1</priref><Empty></Empty><Value>&#38; &amp; é</Value></Object>\r\n</ObjectList>\r\n";
$entry = $archive->capture(new MockHttpClient(new MockResponse($body)), $url, $options, $context + ['password'=>'forbidden']);
check($archive->read($entry['id']) === $body, 'Response bytes changed.');
check($entry['bytes'] === strlen($body) && $entry['sha256'] === hash('sha256', $body), 'Wrong byte count or hash.');
$metadata = file_get_contents($archive->directory().'/'.$entry['id'].'.json');
foreach (['fake-user','fake-password','museumplus.invalid','forbidden'] as $secret) check(!str_contains($metadata, $secret), 'Request credentials or URL leaked to metadata.');
$again = $archive->capture(new MockHttpClient(new MockResponse($body)), $url, $options, $context);
check($again['id'] !== $entry['id'] && $archive->read($entry['id']) === $body, 'Repeated offset overwrote an earlier capture.');

$error = $archive->capture(new MockHttpClient(new MockResponse('Unavailable & raw', ['http_code'=>503])), $url, $options, $context);
check(file_get_contents($archive->directory().'/'.$error['id'].'.xml') === 'Unavailable & raw', 'HTTP error body was lost.');
fails(fn () => $archive->read($error['id']), 'complete HTTP 200');

$before = glob($archive->directory().'/*.json');
$stream = (static function (): Generator { yield '<ObjectList>partial'; throw new TransportException('Simulated transfer failure'); })();
fails(fn () => $archive->capture(new MockHttpClient(new MockResponse($stream)), $url, $options, $context), 'Simulated transfer failure');
$newFiles = array_values(array_diff(glob($archive->directory().'/*.json'), $before));
check(count($newFiles) === 1, 'Failed transport metadata missing.');
$partial = json_decode(file_get_contents($newFiles[0]), true, flags: JSON_THROW_ON_ERROR);
check(!$partial['transfer_complete'] && $partial['error'] === 'transport', 'Partial body marked complete.');
check(file_get_contents($archive->directory().'/'.$partial['id'].'.xml') === '<ObjectList>partial', 'Received partial bytes were lost.');
fails(fn () => $archive->read($partial['id']), 'complete HTTP 200');

file_put_contents($archive->directory().'/'.$entry['id'].'.xml', 'changed');
fails(fn () => $archive->read($entry['id']), 'checksum differs');
fails(fn () => $archive->read('../escape'), 'Invalid raw response ID');
unlink($archive->directory().'/'.$again['id'].'.xml');
fails(fn () => $archive->read($again['id']), 'checksum differs');

$blocked = $archive->directory().'/blocked';
file_put_contents($blocked, 'existing file');
$noRequest = new MockHttpClient(static fn () => throw new LogicException('A request must not be sent'));
fails(fn () => (new RawResponseArchive($blocked))->capture($noRequest, $url, $options, $context), 'Cannot create raw archive directory');
echo "PASS: byte-exact bodies, hashes, private metadata, immutable retries, HTTP/transport evidence, integrity checks and storage failure before fetching\n";

$archive = App\Tests\Support\temporaryRawArchive();
$active = $archive->capture(new MockHttpClient(new MockResponse($body)), $url, $options, $context);
$pending = $archive->capture(new MockHttpClient(new MockResponse($body.'<!-- next -->')), $url, $options, $context);
$obsolete = $archive->capture(new MockHttpClient(new MockResponse($body)), $url, $options, $context);
$failed = $archive->capture(new MockHttpClient(new MockResponse('denied', ['http_code'=>403])), $url, $options, $context);
$directory = $archive->directory();
$orphan = $directory.'/20200101T000000Z-0000000000000000.xml';
file_put_contents($orphan, 'incomplete capture without metadata');
file_put_contents($directory.'/offset-000000000000.xml', 'legacy dump');
file_put_contents($directory.'/manifest.jsonl', 'legacy manifest');
foreach (['notes.txt', 'unrelated.xml', 'unrelated.json'] as $name) file_put_contents($directory.'/'.$name, 'keep');
$nested = $directory.'/20200101T000000Z-1111111111111111.xml';
mkdir($nested);
file_put_contents($nested.'/keep.xml', 'nested content');
$linked = $directory.'/20200101T000000Z-2222222222222222.xml';
$hasLink = @symlink($directory.'/notes.txt', $linked);
try {
    fails(fn () => $archive->prune(['../escape']), 'Invalid raw response ID');
    check(is_file($orphan), 'Invalid retained IDs allowed deletion before validation.');
    check($archive->prune([$active['id'], $pending['id']]) === 7, 'Unexpected obsolete file count.');
    check($archive->read($active['id']) === $body && $archive->read($pending['id']) === $body.'<!-- next -->', 'Pruning changed a published or staged source.');
    foreach ([$obsolete['id'], $failed['id']] as $id) foreach (['xml','json'] as $extension) check(!file_exists($directory.'/'.$id.'.'.$extension), 'Obsolete response survived.');
    foreach (['notes.txt', 'unrelated.xml', 'unrelated.json'] as $name) check(file_get_contents($directory.'/'.$name) === 'keep', 'Unrelated file deleted.');
    check(file_get_contents($nested.'/keep.xml') === 'nested content', 'Pruning entered a subdirectory.');
    if ($hasLink) check(is_link($linked), 'Pruning followed or removed a symbolic link.');
    check($archive->prune([$pending['id']]) === 2, 'Old active source was not removed after publication.');
    check($archive->prune([$pending['id']]) === 0, 'Pruning was not repeatable.');
    check($archive->read($pending['id']) === $body.'<!-- next -->', 'Current source lost after repeated pruning.');
} finally {
    if ($hasLink) unlink($linked);
    unlink($nested.'/keep.xml');
    rmdir($nested);
}
echo "PASS: pruning keeps exact active/staged sources, removes obsolete and legacy dumps, and leaves unrelated files, directories and links alone\n";
