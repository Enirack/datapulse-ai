<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Analysis;
use App\Entity\DatasetVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Analysis>
 */
class AnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Analysis::class);
    }

    public function findLatestForVersion(DatasetVersion $version, string $type): ?Analysis
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.datasetVersion = :version')
            ->andWhere('a.type = :type')
            ->setParameter('version', $version)
            ->setParameter('type', $type)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Generic fingerprint lookup — used by both cleaning (pipeline
     * fingerprint) and analytics (configuration fingerprint), since both
     * reuse Analysis::$pipelineFingerprint as a generic "fingerprint of
     * what was requested" column.
     */
    public function findByFingerprint(DatasetVersion $sourceVersion, string $type, string $fingerprint): ?Analysis
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.datasetVersion = :version')
            ->andWhere('a.type = :type')
            ->andWhere('a.pipelineFingerprint = :fingerprint')
            ->setParameter('version', $sourceVersion)
            ->setParameter('type', $type)
            ->setParameter('fingerprint', $fingerprint)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
