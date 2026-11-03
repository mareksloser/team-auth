<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Veřejný identifikátor = claim `sub`. Nikdy se nemění. */
    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'email_verified', options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, UserTeam> */
    #[ORM\OneToMany(targetEntity: UserTeam::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $teams;

    /** @var Collection<int, UserRole> */
    #[ORM\OneToMany(targetEntity: UserRole::class, mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    private Collection $roleAssignments;

    public function __construct(string $email, string $name)
    {
        $this->uuid = Uuid::v7()->toRfc4122();
        $this->email = mb_strtolower(trim($email));
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->teams = new ArrayCollection();
        $this->roleAssignments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUuid(): string { return $this->uuid; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = mb_strtolower(trim($email)); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
    public function isEmailVerified(): bool { return $this->emailVerified; }
    public function markEmailVerified(): void { $this->emailVerified = true; }
    public function setEmailVerified(bool $verified): void { $this->emailVerified = $verified; }
    public function isSuperadmin(): bool { return \in_array('ROLE_SUPERADMIN', $this->getRoles(), true); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hash): void
    {
        $this->password = $hash;
    }

    /** Role pro samotné IdP (admin). Role aplikací jdou do tokenu jako claim `roles`. */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->roleAssignments as $assignment) {
            if ($assignment->getRole()->isGlobal()) {
                $roles[] = 'ROLE_'.strtoupper($assignment->getRole()->getSlug());
            }
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
    }

    /** @return Collection<int, UserTeam> */
    public function getTeams(): Collection { return $this->teams; }

    /** @return Collection<int, UserRole> */
    public function getRoleAssignments(): Collection { return $this->roleAssignments; }

    public function joinTeam(TeamCategory $team, Membership $membership): UserTeam
    {
        foreach ($this->teams as $existing) {
            if ($existing->getTeam() === $team) {
                $existing->setMembership($membership);

                return $existing;
            }
        }

        $userTeam = new UserTeam($this, $team, $membership);
        $this->teams->add($userTeam);

        return $userTeam;
    }

    public function grant(AppRole $role, ?TeamCategory $team = null): UserRole
    {
        foreach ($this->roleAssignments as $existing) {
            if ($existing->getRole() === $role && $existing->getTeam() === $team) {
                return $existing;
            }
        }

        $assignment = new UserRole($this, $role, $team);
        $this->roleAssignments->add($assignment);

        return $assignment;
    }

    public function revoke(AppRole $role, ?TeamCategory $team = null): void
    {
        foreach ($this->roleAssignments as $existing) {
            if ($existing->getRole() === $role && $existing->getTeam() === $team) {
                $this->roleAssignments->removeElement($existing);
            }
        }
    }
}
