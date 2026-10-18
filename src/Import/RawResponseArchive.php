<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Original response bodies, saved before parsing and outside record transactions. */
final class RawResponseArchive
{
    public function __construct(
        #[Autowire('%museumplus.raw_directory%')]
        private readonly string $directory = __DIR__.'/../../var/museumplus-raw',
    ) {
        if ($directory === '' || str_contains($directory, '://')) {
            throw new RuntimeException('The raw archive must be a local directory.');
        }
    }

    /**
     * Each attempt gets a new ID, including retries and resumed imports.
     * Context contains only source_hash, import_datestamp, offset and limit.
     */
    public function capture(HttpClientInterface $client, string $url, array $options, array $context): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create raw archive directory: '.$this->directory);
        }
        $id = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(8));
        $handle = @fopen($this->path($id, 'xml'), 'xb');
        if ($handle === false) throw new RuntimeException('Cannot create raw response file. No request was sent.');
        $entry = ['id'=>$id, 'captured_at'=>gmdate('c')]
            + array_intersect_key($context, array_flip(['source_hash','import_datestamp','offset','limit']))
            + ['http_status'=>null, 'bytes'=>0, 'transfer_complete'=>false, 'error'=>null];
        $hash = hash_init('sha256');
        $response = null;
        $failure = null;
        try {
            $response = $client->request('POST', $url, $options);
            $entry['http_status'] = $response->getStatusCode();
            foreach ($client->stream($response) as $chunk) {
                $content = $chunk->getContent();
                $this->write($handle, $content);
                $entry['bytes'] += strlen($content);
                hash_update($hash, $content);
            }
            $this->flush($handle);
            $entry['transfer_complete'] = true;
        } catch (\Throwable $error) {
            $entry['error'] = $error instanceof TransportExceptionInterface ? 'transport' : 'storage';
            $failure = $error;
        } finally {
            $response?->cancel();
            fclose($handle);
        }
        $entry['sha256'] = hash_final($hash);
        // Metadata is written only after the body is closed. Never store credentials,
        // request headers, URLs or exception messages here.
        $metadata = @fopen($this->path($id, 'json'), 'xb');
        if ($metadata === false) throw new RuntimeException('Cannot save raw response metadata; import stopped.');
        try {
            $this->write($metadata, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            $this->flush($metadata);
        } finally {
            fclose($metadata);
        }
        if ($failure !== null) throw $failure;

        return $entry;
    }

    /** Read the exact saved body; missing, incomplete or modified sources fail closed. */
    public function read(string $id): string
    {
        $json = @file_get_contents($this->path($id, 'json'));
        if ($json === false) throw new RuntimeException('Missing raw response metadata: '.$id);
        $entry = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (($entry['id'] ?? null) !== $id || ($entry['transfer_complete'] ?? false) !== true
            || ($entry['http_status'] ?? null) !== 200 || ($entry['error'] ?? null) !== null) {
            throw new RuntimeException('Raw response is not a complete HTTP 200 source: '.$id);
        }
        $body = @file_get_contents($this->path($id, 'xml'));
        if ($body === false || strlen($body) !== ($entry['bytes'] ?? null)
            || hash('sha256', $body) !== ($entry['sha256'] ?? null)) {
            throw new RuntimeException('Raw response is missing or its checksum differs: '.$id);
        }

        return $body;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private function path(string $id, string $extension): string
    {
        if (!preg_match('/^\d{8}T\d{6}Z-[a-f0-9]{16}$/D', $id)) throw new RuntimeException('Invalid raw response ID.');

        return rtrim($this->directory, '/\\').'/'.$id.'.'.$extension;
    }

    /** @param resource $handle */
    private function write($handle, string $content): void
    {
        while ($content !== '') {
            $written = @fwrite($handle, $content);
            if ($written === false || $written === 0) throw new RuntimeException('Cannot write raw response archive; import stopped.');
            $content = substr($content, $written);
        }
    }

    /** @param resource $handle */
    private function flush($handle): void
    {
        if (!fflush($handle) || !fsync($handle)) throw new RuntimeException('Cannot flush raw response archive; import stopped.');
    }
}
