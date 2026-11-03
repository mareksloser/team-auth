<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_roles')]
#[ORM\UniqueConstraint(name: 'uniq_user_role_team', columns: ['user_id', 'role_id', 'team_id'])]
class UserRole
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'roleAssignments')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'role_id', nullable: false, onDelete: 'CASCADE')]
    private AppRole $role;

    /** NULL = role platí pro všechny týmy */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'team_id', nullable: true, onDelete: 'CASCADE')]
    private ?TeamCategory $team;

    public function __construct(User $user, AppRole $role, ?TeamCategory $team = null)
    {
        $this->user = $user;
        $this->role = $role;
        $this->team = $team;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getRole(): AppRole { return $this->role; }
    public function getTeam(): ?TeamCategory { return $this->team; }

    /** Tvar v tokenu: "coach" nebo "coach@u11" */
    public function toClaim(): string
    {
        return null === $this->team ? $this->role->getSlug() : $this->role->getSlug().'@'.$this->team->getSlug();
    }
}
