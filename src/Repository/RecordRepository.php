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
        return ($this->findForHarvest(null, null, null, 1, 0)[0] ?? null)?->getDatestamp();
    }

    /**
     * @return list<string>
     */
    public function findSetSpecs(): array
    {
        return array_values(array_filter(array_keys(Record::PUBLICATION_FIELDS), fn (string $set): bool => $this->countForHarvest(null, null, $set) > 0));
    }

    /**
     * @return list<Record>
     */
    public function findForHarvest(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?string $setSpec,
        int $limit,
        int $offset,
    ): array {
        $queryBuilder = $this->filteredHarvestQuery($from, $until, $setSpec)
            ->orderBy('r.datestamp', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return list<array{oaiIdentifier: string, axiellPriref: string|null, datestamp: DateTimeImmutable, setSpecs: list<string>}>
     */
    public function findHeadersForHarvest(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?string $setSpec,
        int $limit,
        int $offset,
    ): array {
        $queryBuilder = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('r.oai_identifier', 'r.axiell_priref', 'r.datestamp', ...array_map(static fn (string $field): string => 'r.'.$field, array_values(Record::PUBLICATION_COLUMNS)))
            ->from('records', 'r')
            ->orderBy('r.datestamp', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($from !== null) {
            $queryBuilder
                ->andWhere('r.datestamp >= :from')
                ->setParameter('from', $from->format('Y-m-d H:i:s'));
        }

        if ($until !== null) {
            $queryBuilder
                ->andWhere('r.datestamp <= :until')
                ->setParameter('until', $until->format('Y-m-d H:i:s'));
        }

        $queryBuilder->andWhere(self::publicationCondition($setSpec, Record::PUBLICATION_COLUMNS));

        $rows = $queryBuilder->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'oaiIdentifier' => (string) $row['oai_identifier'],
                'axiellPriref' => $row['axiell_priref'],
                'datestamp' => new DateTimeImmutable((string) $row['datestamp'], new DateTimeZone('UTC')),
                'setSpecs' => array_keys(array_filter(Record::PUBLICATION_COLUMNS, static fn (string $column): bool => (bool) $row[$column])),
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
