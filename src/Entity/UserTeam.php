<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_teams')]
#[ORM\UniqueConstraint(name: 'uniq_user_team', columns: ['user_id', 'team_id'])]
class UserTeam
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'teams')]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'team_id', nullable: false, onDelete: 'CASCADE')]
    private TeamCategory $team;

    #[ORM\Column(length: 20, enumType: Membership::class)]
    private Membership $membership;

    public function __construct(User $user, TeamCategory $team, Membership $membership)
    {
        $this->user = $user;
        $this->team = $team;
        $this->membership = $membership;
    }

    public function getUser(): User { return $this->user; }
    public function getTeam(): TeamCategory { return $this->team; }
    public function getMembership(): Membership { return $this->membership; }
    public function setMembership(Membership $membership): void { $this->membership = $membership; }
}
