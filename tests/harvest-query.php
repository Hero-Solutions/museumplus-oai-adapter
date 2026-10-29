<?php

declare(strict_types=1);

// Generate actual MariaDB SQL, but intercept execution. No kernel, .env or database.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Entity\Record;
use App\Repository\RecordRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class HarvestQueryConnection extends Connection
{
    public array $queries = [];
    public array $rows = [];

    public function __construct() {}

    protected function connect(): DriverConnection
    {
        throw new RuntimeException('Database connections are forbidden in this test.');
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new MariaDBPlatform();
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->queries[] = ['sql' => $query, 'params' => $params];

        return $this->rows;
    }
}

$connection = new HarvestQueryConnection();
// Inject only the connection needed by the page reader; no registry or live ORM setup.
$manager = (new ReflectionClass(EntityManager::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(EntityManager::class, 'conn'))->setValue($manager, $connection);
$repository = (new ReflectionClass(RecordRepository::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ServiceEntityRepository::class, 'repository'))->setValue(
    $repository,
    new EntityRepository($manager, new ClassMetadata(Record::class)),
);

function pageSql(HarvestQueryConnection $connection, int $limit, int $offset): array
{
    check(count($connection->queries) === 1, 'A page must use one SQL statement, including empty pages.');
    $query = $connection->queries[0];
    $sql = preg_replace('/\s+/', ' ', $query['sql']);
    check(preg_match('/^SELECT (.+?) FROM records r INNER JOIN \((SELECT .+)\) page ON page.id = r.id ORDER BY r.datestamp ASC, r.id ASC$/', $sql, $parts) === 1, 'Page must join its bounded ID selection and restore stable output order.');
    check(str_starts_with($parts[2], 'SELECT r.id FROM records r WHERE '), 'Offset scan must select only IDs.');
    $pagination = 'ORDER BY r.datestamp ASC, r.id ASC LIMIT '.$limit.($offset === 0 ? '' : ' OFFSET '.$offset);
    check(str_ends_with($parts[2], $pagination), 'Limit, offset and stable ordering must stay inside the ID subquery.');
    check(substr_count($sql, 'LIMIT ') === 1, 'Outer payload query must not apply pagination again.');
    foreach (['museumplus_xml', 'raw_response_id', 'oai_identifier', 'object_number', '*'] as $unused) {
        check(!str_contains($sql, $unused), 'Page unnecessarily reads '.$unused.'.');
    }

    return [$parts[1], $parts[2], $query['params']];
}

foreach (Record::PUBLICATION_COLUMNS as $set => $column) {
    foreach ([false, true] as $filteredDates) {
        $from = $filteredDates ? new DateTimeImmutable('2026-10-01T00:00:00Z') : null;
        $until = $filteredDates ? new DateTimeImmutable('2026-10-29T23:59:59Z') : null;
        $connection->queries = [];
        $connection->rows = [
            ['axiell_priref' => '00123', 'datestamp' => '2026-10-20 12:34:56', 'xml' => '<record><title>Exact &amp; intact</title></record>'],
            ['axiell_priref' => '00456', 'datestamp' => '2026-10-20 12:34:56', 'xml' => null],
        ];
        $rows = $repository->findForHarvest($from, $until, $set, 100, 5700);
        [$selected, $page, $params] = pageSql($connection, 100, 5700);
        $xmlColumn = $set === 'vkc' ? 'oai_xml' : 'oai_xml_native';
        check($selected === 'r.axiell_priref, r.datestamp, r.'.$xmlColumn.' AS xml', 'Read only the requested profile XML and its header.');
        check(str_contains($page, 'r.'.$column.' = 1') && !str_contains($page, ' OR '), 'Set selection must apply before pagination.');
        foreach (Record::PUBLICATION_COLUMNS as $other) {
            if ($other !== $column) check(!str_contains($page, $other), 'Page mixes publication flags.');
        }
        if ($filteredDates) {
            check(str_contains($page, 'r.datestamp >= :from') && str_contains($page, 'r.datestamp <= :until'), 'Date boundaries must filter the ID selection inclusively.');
            check($params === ['from' => '2026-10-01 00:00:00', 'until' => '2026-10-29 23:59:59'], 'Date parameters were lost between inner and outer query.');
            check(!str_contains($page, '2026-'), 'Dates must remain bound parameters.');
        } else {
            check($params === [] && !str_contains($page, ':from') && !str_contains($page, ':until'), 'Unfiltered pages acquired date restrictions.');
        }
        check(array_column($rows, 'axiellPriref') === ['00123', '00456'], 'Header identity or returned order changed.');
        check($rows[0]['datestamp']->format('Y-m-d\TH:i:sP') === '2026-10-20T12:34:56+00:00', 'Datestamp lost UTC interpretation.');
        check($rows[0]['xml'] === $connection->rows[0]['xml'] && $rows[1]['xml'] === null, 'Payload or missing-profile indication changed.');
        check(array_keys($rows[0]) === ['axiellPriref', 'datestamp', 'xml'], 'Unexpected page data exposed.');

        $connection->queries = [];
        $connection->rows = [['axiell_priref' => '00123', 'datestamp' => '2026-10-20 12:34:56']];
        $headers = $repository->findHeadersForHarvest($from, $until, $set, 7, 5700);
        [$selected, $headerPage, $headerParams] = pageSql($connection, 7, 5700);
        check($selected === 'r.axiell_priref, r.datestamp', 'ListIdentifiers must not fetch XML.');
        check(str_replace('LIMIT 7', 'LIMIT 100', $headerPage) === $page && $headerParams === $params, 'Headers and records must select the same IDs.');
        check(!array_key_exists('xml', $headers[0]) && $headers[0]['axiellPriref'] === '00123', 'Header shape changed.');
    }
}

$connection->queries = [];
$connection->rows = [];
check($repository->findForHarvest(null, null, 'vkc', 100, 99999) === [], 'Empty page must stay empty.');
pageSql($connection, 100, 99999);
$connection->queries = [];
check($repository->findForHarvest(null, null, "invalid' OR 1=1", 100, 0) === [], 'Unknown set must not publish records.');
[, $page] = pageSql($connection, 100, 0);
check(str_contains($page, 'WHERE 1 = 0') && !str_contains($page, 'invalid'), 'Unknown sets must fail closed without interpolating input.');

$connection->queries = [];
check($repository->findEarliestDatestamp() === null, 'Empty repository must have no earliest date.');
[$selected, $page] = pageSql($connection, 1, 0);
check($selected === 'r.axiell_priref, r.datestamp', 'Identify must not read XML.');
foreach (Record::PUBLICATION_COLUMNS as $column) check(str_contains($page, 'r.'.$column.' = 1'), 'Identify must consider all published sets.');
$connection->queries = [];
$connection->rows = [['axiell_priref' => null, 'datestamp' => '2026-10-01 00:00:00']];
check($repository->findEarliestDatestamp()?->format('Y-m-d') === '2026-10-01', 'Identify lost the earliest date.');
pageSql($connection, 1, 0);

echo "PASS: MariaDB page SQL selects IDs before payloads; four sets, bound dates, stable order, one statement, narrow headers and empty pages\n";
