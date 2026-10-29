<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Record;
use App\Oai\HarvestRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Record>
 */
final class RecordRepository extends ServiceEntityRepository implements HarvestRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Record::class);
    }

    public function findOneByAxiellPriref(string $priref): ?Record
    {
        $record = $this->findOneBy(['axiellPriref' => $priref]);

        return $record !== null && $record->getSetSpecs() !== [] ? $record : null;
    }

    public function findEarliestDatestamp(): ?DateTimeImmutable
    {
        return $this->findHeadersForHarvest(null, null, null, 1, 0)[0]['datestamp'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function findSetSpecs(): array
    {
        return array_values(array_filter(array_keys(Record::PUBLICATION_FIELDS), fn (string $set): bool => $this->countForHarvest(null, null, $set) > 0));
    }

    /**
     * @return list<array{axiellPriref: string|null, datestamp: DateTimeImmutable, xml: string|null}>
     */
    public function findForHarvest(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        string $setSpec,
        int $limit,
        int $offset,
    ): array {
        $xmlColumn = $setSpec === 'vkc' ? 'r.oai_xml' : 'r.oai_xml_native';

        return $this->harvestPage($from, $until, $setSpec, $limit, $offset, $xmlColumn);
    }

    /**
     * @return list<array{axiellPriref: string|null, datestamp: DateTimeImmutable}>
     */
    public function findHeadersForHarvest(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?string $setSpec,
        int $limit,
        int $offset,
    ): array {
        return $this->harvestPage($from, $until, $setSpec, $limit, $offset);
    }

    private function harvestPage(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?string $setSpec,
        int $limit,
        int $offset,
        ?string $xmlColumn = null,
    ): array {
        $connection = $this->getEntityManager()->getConnection();
        // Keep OFFSET on the narrow index scan. Read payloads only for the selected IDs.
        // One statement also keeps both steps on the same table during an import swap.
        $page = $connection->createQueryBuilder()
            ->select('r.id')
            ->from('records', 'r')
            ->where(self::publicationCondition($setSpec, Record::PUBLICATION_COLUMNS))
            ->orderBy('r.datestamp', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($from !== null) {
            $page
                ->andWhere('r.datestamp >= :from')
                ->setParameter('from', $from->format('Y-m-d H:i:s'));
        }

        if ($until !== null) {
            $page
                ->andWhere('r.datestamp <= :until')
                ->setParameter('until', $until->format('Y-m-d H:i:s'));
        }

        $columns = 'r.axiell_priref, r.datestamp';
        if ($xmlColumn !== null) $columns .= ', '.$xmlColumn.' AS xml';
        $rows = $connection->fetchAllAssociative(
            'SELECT '.$columns.' FROM records r INNER JOIN ('.$page->getSQL().') page ON page.id = r.id ORDER BY r.datestamp ASC, r.id ASC',
            $page->getParameters(),
            $page->getParameterTypes(),
        );

        return array_map(
            static fn (array $row): array => [
                'axiellPriref' => $row['axiell_priref'],
                'datestamp' => new DateTimeImmutable((string) $row['datestamp'], new DateTimeZone('UTC')),
                ...($xmlColumn === null ? [] : ['xml' => $row['xml']]),
            ],
            $rows,
        );
    }

    public function countForHarvest(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec): int
    {
        return (int) $this->filteredHarvestQuery($from, $until, $setSpec)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filteredHarvestQuery(?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $setSpec): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('r');

        if ($from !== null) {
            $queryBuilder
                ->andWhere('r.datestamp >= :from')
                ->setParameter('from', $from);
        }

        if ($until !== null) {
            $queryBuilder
                ->andWhere('r.datestamp <= :until')
                ->setParameter('until', $until);
        }

        $queryBuilder->andWhere(self::publicationCondition($setSpec, Record::PUBLICATION_FIELDS));

        return $queryBuilder;
    }

    private static function publicationCondition(?string $set, array $fields): string
    {
        if ($set !== null) return isset($fields[$set]) ? 'r.'.$fields[$set].' = 1' : '1 = 0';

        return '('.implode(' OR ', array_map(static fn (string $field): string => 'r.'.$field.' = 1', array_values($fields))).')';
    }
}
