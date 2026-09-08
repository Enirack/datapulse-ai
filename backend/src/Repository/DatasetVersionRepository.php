<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Dataset;
use App\Entity\DatasetVersion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasetVersion>
 */
class DatasetVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetVersion::class);
    }

    /**
     * @return list<DatasetVersion>
     */
    public function findByDataset(Dataset $dataset): array
    {
        return $this->createQueryBuilder('dv')
            ->andWhere('dv.dataset = :dataset')
            ->setParameter('dataset', $dataset)
            ->orderBy('dv.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByChecksumForOwner(string $checksum, User $owner): ?DatasetVersion
    {
        return $this->createQueryBuilder('dv')
            ->innerJoin('dv.dataset', 'd')
            ->andWhere('dv.checksum = :checksum')
            ->andWhere('d.owner = :owner')
            ->setParameter('checksum', $checksum)
            ->setParameter('owner', $owner)
            ->orderBy('dv.createdAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
