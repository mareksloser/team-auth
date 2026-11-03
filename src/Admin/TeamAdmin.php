<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditLogger;
use App\Entity\TeamCategory;
use App\Entity\User;
use App\Entity\UserTeam;
use Doctrine\ORM\EntityManagerInterface;

final class TeamAdmin
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return TeamCategory[] */
    public function all(): array
    {
        return $this->em->getRepository(TeamCategory::class)->findBy([], ['name' => 'ASC']);
    }

    /** @return array<int, int> team id => počet členů */
    public function memberCounts(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(ut.team) AS team, COUNT(ut.id) AS cnt')
            ->from(UserTeam::class, 'ut')
            ->groupBy('ut.team')
            ->getQuery()->getArrayResult();

        return array_column($rows, 'cnt', 'team');
    }

    public function create(string $slug, string $name, User $actor): TeamCategory
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
            throw new AdminException('Zkratka smí obsahovat jen a-z, 0-9 a pomlčku (max. 50 znaků).');
        }
        if (null !== $this->em->getRepository(TeamCategory::class)->findOneBy(['slug' => $slug])) {
            throw new AdminException('Tým s touto zkratkou už existuje.');
        }

        $team = new TeamCategory($slug, $this->validName($name));
        $this->em->persist($team);
        $this->em->flush();
        $this->audit->log('admin.team_create', $actor->getUuid(), null, ['team' => $slug]);

        return $team;
    }

    public function rename(TeamCategory $team, string $name, User $actor): void
    {
        $team->setName($this->validName($name));
        $this->em->flush();
        $this->audit->log('admin.team_rename', $actor->getUuid(), null, ['team' => $team->getSlug()]);
    }

    /** Smaže i členství a role omezené na tento tým (ON DELETE CASCADE). */
    public function delete(TeamCategory $team, User $actor): void
    {
        $slug = $team->getSlug();
        $this->em->remove($team);
        $this->em->flush();
        $this->audit->log('admin.team_delete', $actor->getUuid(), null, ['team' => $slug]);
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 100) {
            throw new AdminException('Název je povinný (max. 100 znaků).');
        }

        return $name;
    }
}
