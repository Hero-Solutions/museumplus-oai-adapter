<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Record;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Record>
 */
final class RecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Record::class);
    }

    public function findOneByOaiIdentifier(string $identifier): ?Record
    {
        return $this->findOneBy(['oaiIdentifier' => $identifier]);
    }

    public function findEarliestDatestamp(): ?DateTimeImmutable
    {
        return $this->findOneBy([], ['datestamp' => 'ASC'])?->getDatestamp();
    }

    /**
     * @return list<string>
     */
    public function findSetSpecs(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.setSpec AS setSpec')
            ->orderBy('r.setSpec', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['setSpec'], $rows));
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
     * @return list<array{oaiIdentifier: string, datestamp: DateTimeImmutable, setSpec: string}>
     */
    public function findHeadersForHarvest(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $until,
        ?string $setSpec,
        int $limit,
        int $offset,
    ): array {
        $queryBuilder = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('r.oai_identifier', 'r.datestamp', 'r.set_spec')
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

        if ($setSpec !== null) {
            $queryBuilder
                ->andWhere('r.set_spec = :setSpec')
                ->setParameter('setSpec', $setSpec);
        }

        $rows = $queryBuilder->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'oaiIdentifier' => (string) $row['oai_identifier'],
                'datestamp' => new DateTimeImmutable((string) $row['datestamp'], new DateTimeZone('UTC')),
                'setSpec' => (string) $row['set_spec'],
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

        if ($setSpec !== null) {
            $queryBuilder
                ->andWhere('r.setSpec = :setSpec')
                ->setParameter('setSpec', $setSpec);
        }

        return $queryBuilder;
    }
}
