<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\MuseumPlusImportState;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export-museumplus-source',
    description: 'Export all stored MuseumPlus source XML, without mapping, parsing or fetching.',
)]
final class ExportMuseumPlusSourceCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'New local XML file. Existing files are never overwritten.')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Source table: records or records_import.', 'records');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');
        $table = $input->getOption('table');
        if (!is_string($file) || trim($file) === '' || str_contains($file, '://')) {
            throw new RuntimeException('Provide a local output file.');
        }
        if (!in_array($table, ['records', 'records_import'], true)) {
            throw new RuntimeException('--table must be records or records_import.');
        }

        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create output file. Check the directory and choose a filename that does not already exist.');
        }

        $progress = new MuseumPlusImportState($this->connection);
        $locked = false;
        $complete = false;
        $count = 0;
        try {
            // Prevent imports/rebuilds (including table swaps) while paging the source.
            $progress->lock();
            $locked = true;
            $io->writeln(sprintf('Exporting all stored museumplus_xml from %s. No MuseumPlus requests or record transformations.', $table));
            $this->write($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ObjectList>\n");
            $lastId = 0;
            while (($rows = $this->connection->fetchAllAssociative(
                'SELECT id, museumplus_xml FROM '.$table.' WHERE id > ? ORDER BY id LIMIT 100',
                [$lastId],
            )) !== []) {
                foreach ($rows as $row) {
                    $source = $row['museumplus_xml'];
                    if (!is_string($source) || trim($source) === '') {
                        throw new RuntimeException(sprintf('Missing museumplus_xml at database row %s. Export stopped; no records were silently omitted.', $row['id']));
                    }
                    // Copy column bytes exactly; only add the document wrapper/separators.
                    $this->write($handle, $source);
                    $this->write($handle, "\n");
                    $lastId = (int) $row['id'];
                    ++$count;
                }
            }
            $this->write($handle, "</ObjectList>\n");
            if (!fflush($handle)) {
                throw new RuntimeException('Could not flush the export file.');
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete && !@unlink($file)) {
                $io->warning('Could not remove the incomplete export: '.$file);
            }
            if ($locked) {
                $progress->unlock();
            }
        }

        $io->success(sprintf('Exported %d records from %s to %s. Stored source XML was copied unchanged.', $count, $table, $file));
        $io->note('This is the stored source XML, not a byte-exact archive of the original HTTP responses.');

        return Command::SUCCESS;
    }

    /** @param resource $handle */
    private function write($handle, string $content): void
    {
        while ($content !== '') {
            $written = fwrite($handle, $content);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the export file.');
            }
            $content = substr($content, $written);
        }
    }
}
