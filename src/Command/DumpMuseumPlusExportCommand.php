<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\MuseumPlusSearch;
use DOMDocument;
use DOMElement;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:dump-museumplus-export',
    description: 'Save every response body of the configured MuseumPlus export unchanged, one file per batch.',
)]
final class DumpMuseumPlusExportCommand extends Command
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'New local directory for raw responses and manifest.jsonl.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Requested number of objects per response.', '1000')
            ->addOption('start-offset', null, InputOption::VALUE_REQUIRED, 'Start at this offset, e.g. after an interrupted capture in another directory.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');
        if (!is_string($directory) || trim($directory) === '' || str_contains($directory, '://')) {
            throw new RuntimeException('Provide a new local output directory.');
        }
        $directory = rtrim($directory, '/\\');
        $limit = $this->integer($input->getOption('batch-size'), '--batch-size', 1);
        $start = $this->integer($input->getOption('start-offset'), '--start-offset', 0);
        $url = $this->env('MUSEUMPLUS_EXPORT_URL');
        $username = $this->env('MUSEUMPLUS_USERNAME');
        $password = $this->env('MUSEUMPLUS_PASSWORD');
        $field = $this->env('MUSEUMPLUS_SEARCH_FIELD_PATH');
        $operand = $this->env('MUSEUMPLUS_SEARCH_OPERAND');
        $orgUnits = MuseumPlusSearch::parseOrgUnits($this->env('MUSEUMPLUS_ORG_UNITS', ''));
        $timeout = $this->integer($this->env('MUSEUMPLUS_TIMEOUT_SECONDS'), 'MUSEUMPLUS_TIMEOUT_SECONDS', 1);

        // Require a fresh directory: retries never overwrite previous evidence.
        if ($directory === '' || !@mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create output directory. Its parent must exist; choose a new directory name.');
        }
        $manifest = @fopen($directory.'/manifest.jsonl', 'xb');
        if ($manifest === false) {
            throw new RuntimeException('Cannot create manifest.jsonl. No requests were sent.');
        }

        $offset = $start;
        $total = 0;
        $previousHash = null;
        try {
            $this->writeManifest($manifest, [
                'type' => 'start', 'started_at' => gmdate('c'),
                'start_offset' => $start, 'batch_size' => $limit,
                // Record source/selection identity without storing credentials or a URL.
                'source_hash' => hash('sha256', json_encode([$url, $field, $operand, $orgUnits], JSON_THROW_ON_ERROR)),
            ]);
            $io->note('Uses the same export and filters as the import. Saves original response bodies; no database changes.');
            while (true) {
                $name = sprintf('offset-%012d.xml', $offset);
                $io->writeln(sprintf('Fetching offset %d, limit %d into %s.', $offset, $limit, $name));
                $entry = $this->saveResponse($directory.'/'.$name, $url, [
                    'auth_basic' => [$username, $password],
                    'headers' => [
                        'Accept' => 'application/octet-stream', 'Content-Type' => 'application/xml',
                        'Accept-Encoding' => 'identity',
                    ],
                    'body' => MuseumPlusSearch::xml($field, $operand, $limit, $offset, $orgUnits),
                    'timeout' => $timeout, 'buffer' => false, 'max_redirects' => 0,
                ]);
                $entry = ['type' => 'response', 'file' => $name, 'offset' => $offset, 'limit' => $limit] + $entry;
                $count = null;
                if ($entry['error'] === null && $entry['http_status'] !== 200) {
                    $entry['error'] = 'Unexpected HTTP status '.$entry['http_status'].'.';
                }
                if ($entry['error'] === null) {
                    try {
                        // Inspect the saved copy only for paging; never serialise it again.
                        $count = $this->countObjects($directory.'/'.$name);
                        if ($count > $limit) {
                            throw new RuntimeException('Response exceeds the requested limit.');
                        }
                        if ($count > 0 && $entry['sha256'] === $previousHash) {
                            throw new RuntimeException('Response repeats the previous batch; the server may have ignored the offset.');
                        }
                    } catch (RuntimeException $exception) {
                        $entry['error'] = $exception->getMessage();
                    }
                }
                $entry['object_count'] = $count;
                $entry['next_offset'] = $entry['error'] === null ? $offset + $count : $offset;
                $entry['result'] = $entry['error'] !== null ? 'error' : ($count === 0 ? 'complete' : 'saved');
                $this->writeManifest($manifest, $entry);
                if ($entry['error'] !== null) {
                    $io->error(sprintf('%s Received bytes are kept in %s. Offset %d was not advanced. Retry with --start-offset=%d in a NEW directory.', $entry['error'], $directory.'/'.$name, $offset, $offset));

                    return Command::FAILURE;
                }
                $io->writeln(sprintf('Saved %d bytes unchanged; %d objects.', $entry['bytes'], $count));
                if ($count === 0) {
                    break;
                }
                $total += $count;
                $offset = $entry['next_offset'];
                $previousHash = $entry['sha256'];
            }
        } finally {
            fclose($manifest);
        }

        $io->success(sprintf('Finished from offset %d: %d objects captured in %s, including the final empty response. Every response body was saved unchanged.', $start, $total, $directory));

        return Command::SUCCESS;
    }

    /** @return array{http_status: ?int, bytes: int, sha256: string, transfer_complete: bool, error: ?string} */
    private function saveResponse(string $file, string $url, array $options): array
    {
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create response file: '.$file);
        }
        $response = null;
        $status = null;
        $bytes = 0;
        $complete = false;
        $error = null;
        $hash = hash_init('sha256');
        try {
            $response = $this->httpClient->request('POST', $url, $options);
            $status = $response->getStatusCode();
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();
                $this->write($handle, $content);
                $bytes += strlen($content);
                hash_update($hash, $content);
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush response file: '.$file);
            }
            $complete = true;
        } catch (TransportExceptionInterface) {
            // Keep received bytes. Never retry into the same file or guess an offset.
            $error = 'Transport failed; the response may be incomplete.';
        } finally {
            $response?->cancel();
            fclose($handle);
        }

        return ['http_status' => $status, 'bytes' => $bytes, 'sha256' => hash_final($hash), 'transfer_complete' => $complete, 'error' => $error];
    }

    private function countObjects(string $file): int
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument();
            $loaded = $document->load($file, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype !== null || $document->documentElement?->localName !== 'ObjectList') {
            throw new RuntimeException('Response is not a complete ObjectList XML document. Raw bytes have not been repaired.');
        }
        $count = 0;
        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($child->localName !== 'Object') {
                    throw new RuntimeException('Unexpected element in ObjectList: '.$child->localName.'.');
                }
                ++$count;
            } elseif (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true) && trim($child->textContent) !== '') {
                throw new RuntimeException('Unexpected text in ObjectList.');
            }
        }

        return $count;
    }

    /** @param resource $handle */
    private function writeManifest($handle, array $entry): void
    {
        $this->write($handle, json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        if (!fflush($handle)) {
            throw new RuntimeException('Could not flush manifest.jsonl; capture stopped.');
        }
    }

    /** @param resource $handle */
    private function write($handle, string $content): void
    {
        while ($content !== '') {
            $written = fwrite($handle, $content);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write output; capture stopped. Received files may be incomplete.');
            }
            $content = substr($content, $written);
        }
    }

    private function integer(mixed $value, string $name, int $minimum): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
        if ($parsed === false) {
            throw new RuntimeException(sprintf('%s must be an integer of at least %d.', $name, $minimum));
        }

        return $parsed;
    }

    private function env(string $name, ?string $default = null): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value) || trim($value) === '') {
            return $default ?? throw new RuntimeException('Missing required env var '.$name.'.');
        }

        return $value;
    }
}
