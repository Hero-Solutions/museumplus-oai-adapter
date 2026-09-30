<?php

declare(strict_types=1);

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:dump-museumplus-response',
    description: 'Save the raw response for one MuseumPlus object without parsing XML or changing the import.',
)]
final class DumpMuseumPlusResponseCommand extends Command
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('offset', InputArgument::REQUIRED, 'Export offset to fetch, not a MuseumPlus object ID.')
            ->addArgument('file', InputArgument::REQUIRED, 'New local file for the raw response. Existing files are never overwritten.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $offset = filter_var($input->getArgument('offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $file = $input->getArgument('file');

        if ($offset === false || !is_string($file) || trim($file) === '' || str_contains($file, '://')) {
            throw new RuntimeException('Provide a non-negative offset and a local output file.');
        }

        $url = $this->env('MUSEUMPLUS_EXPORT_URL');
        $username = $this->env('MUSEUMPLUS_USERNAME');
        $password = $this->env('MUSEUMPLUS_PASSWORD');
        $fieldPath = htmlspecialchars($this->env('MUSEUMPLUS_SEARCH_FIELD_PATH'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $operand = htmlspecialchars($this->env('MUSEUMPLUS_SEARCH_OPERAND'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $timeout = filter_var($this->env('MUSEUMPLUS_TIMEOUT_SECONDS'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($timeout === false) {
            throw new RuntimeException('MUSEUMPLUS_TIMEOUT_SECONDS must be a positive integer.');
        }

        $handle = @fopen($file, 'xb');

        if ($handle === false) {
            throw new RuntimeException('Cannot create output file. Check the directory and choose a filename that does not already exist.');
        }

        $response = null;
        $bytes = 0;

        try {
            // Same export and search as the importer, deliberately limited to one object.
            $response = $this->httpClient->request('POST', $url, [
                'auth_basic' => [$username, $password],
                'headers' => ['Accept' => 'application/octet-stream', 'Content-Type' => 'application/xml'],
                'body' => <<<XML
                    <?xml version="1.0" encoding="UTF-8"?>
                    <application xmlns="http://www.zetcom.com/ria/ws/module/search">
                      <modules>
                        <module name="Object">
                          <search limit="1" offset="$offset">
                            <expert>
                              <equalsField fieldPath="$fieldPath" operand="$operand"/>
                            </expert>
                          </search>
                        </module>
                      </modules>
                    </application>
                    XML,
                'timeout' => $timeout,
                'buffer' => false,
            ]);
            $statusCode = $response->getStatusCode();

            // Preserve every received byte, including partial bodies on transport errors.
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();

                while ($content !== '') {
                    $written = fwrite($handle, $content);

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Could not write the response to '.$file.'.');
                    }

                    $bytes += $written;
                    $content = substr($content, $written);
                }
            }

            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the response to '.$file.'.');
            }
        } catch (TransportExceptionInterface $exception) {
            $io->error(sprintf('Transport failed at offset %d. Kept %d received bytes in %s; the file may be incomplete. %s', $offset, $bytes, $file, $exception->getMessage()));

            return Command::FAILURE;
        } finally {
            $response?->cancel();
            fclose($handle);
        }

        $io->writeln(sprintf('Saved HTTP %d response for offset %d (%d bytes) to %s. XML was not parsed.', $statusCode, $offset, $bytes, $file));

        return $statusCode >= 400 ? Command::FAILURE : Command::SUCCESS;
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Missing required env var %s.', $name));
        }

        return $value;
    }
}
