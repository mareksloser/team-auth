<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findActiveByUuid(string $uuid): ?User
    {
        return $this->findOneBy(['uuid' => $uuid, 'active' => true]);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /** @return User[] */
    public function search(?string $query, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.name', 'ASC')->setMaxResults($limit)->setFirstResult($offset);
        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('u.email LIKE :q OR u.name LIKE :q')->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return array<string, User> uuid => User */
    public function mapByUuids(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids)));
        if ([] === $uuids) {
            return [];
        }
        $map = [];
        foreach ($this->findBy(['uuid' => $uuids]) as $user) {
            $map[$user->getUuid()] = $user;
        }

        return $map;
    }
}
