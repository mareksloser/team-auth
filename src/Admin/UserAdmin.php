<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditLogger;
use App\Device\DeviceTracker;
use App\Entity\AppRole;
use App\Entity\Membership;
use App\Entity\TeamCategory;
use App\Entity\User;
use App\Entity\UserRole;
use App\Entity\UserTeam;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** User operations for the admin. Controllers only pass input in and render the result. */
final class UserAdmin
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $audit,
        private readonly DeviceTracker $devices,
    ) {
    }

    public function create(string $email, string $name, string $password, User $actor): User
    {
        $email = $this->validEmail($email);
        $name = $this->validName($name);
        if (null !== $this->users->findOneByEmail($email)) {
            throw new AdminException('Uživatel s tímto e-mailem už existuje.');
        }

        $user = new User($email, $name);
        $user->setPassword($this->hashedPassword($user, $password));
        $user->markEmailVerified();

        $this->em->persist($user);
        $this->em->flush();
        $this->audit->log('admin.user_create', $actor->getUuid(), null, ['target' => $user->getUuid()]);

        return $user;
    }

    public function update(User $user, string $email, string $name, bool $active, bool $emailVerified, User $actor): void
    {
        $email = $this->validEmail($email);
        $existing = $this->users->findOneByEmail($email);
        if (null !== $existing && $existing !== $user) {
            throw new AdminException('Tento e-mail už používá jiný uživatel.');
        }
        if ($user === $actor && !$active) {
            throw new AdminException('Sám sebe deaktivovat nemůžeš.');
        }

        $user->setEmail($email);
        $user->setName($this->validName($name));
        $user->setActive($active);
        $user->setEmailVerified($emailVerified);
        $this->em->flush();
        if (!$active) {
            $this->devices->signOutEverywhere($user);
        }
        $this->audit->log('admin.user_update', $actor->getUuid(), null, ['target' => $user->getUuid(), 'active' => $active]);
    }

    public function setPassword(User $user, string $password, User $actor): void
    {
        $user->setPassword($this->hashedPassword($user, $password));
        $this->em->flush();
        $this->devices->signOutEverywhere($user); // a new password ends all existing sign-ins
        $this->audit->log('admin.user_password', $actor->getUuid(), null, ['target' => $user->getUuid()]);
    }

    public function signOutEverywhere(User $user, User $actor): void
    {
        $this->devices->signOutEverywhere($user);
        $this->audit->log('admin.devices_logout', $actor->getUuid(), null, ['target' => $user->getUuid()]);
    }

    public function delete(User $user, User $actor): void
    {
        if ($user === $actor) {
            throw new AdminException('Sám sebe smazat nemůžeš.');
        }
        $uuid = $user->getUuid();
        $this->em->remove($user);
        $this->em->flush();
        $this->audit->log('admin.user_delete', $actor->getUuid(), null, ['target' => $uuid]);
    }

    public function addTeam(User $user, TeamCategory $team, Membership $membership, User $actor): void
    {
        $user->joinTeam($team, $membership);
        $this->em->flush();
        $this->audit->log('admin.team_add', $actor->getUuid(), null, ['target' => $user->getUuid(), 'team' => $team->getSlug(), 'membership' => $membership->value]);
    }

    public function removeTeam(User $user, UserTeam $membership, User $actor): void
    {
        if ($membership->getUser() !== $user) {
            throw new AdminException('Členství nepatří tomuto uživateli.');
        }
        $user->getTeams()->removeElement($membership);
        $this->em->flush();
        $this->audit->log('admin.team_remove', $actor->getUuid(), null, ['target' => $user->getUuid(), 'team' => $membership->getTeam()->getSlug()]);
    }

    public function grant(User $user, AppRole $role, ?TeamCategory $team, User $actor): void
    {
        if ($role->isGlobal() && null !== $team) {
            throw new AdminException('Globální roli nelze omezit na tým.');
        }
        $assignment = $user->grant($role, $team);
        $this->em->flush();
        $this->audit->log('admin.role_grant', $actor->getUuid(), $role->getClientId(), ['target' => $user->getUuid(), 'role' => $assignment->toClaim()]);
    }

    public function revoke(User $user, UserRole $assignment, User $actor): void
    {
        if ($assignment->getUser() !== $user) {
            throw new AdminException('Role nepatří tomuto uživateli.');
        }
        if ($user === $actor && $assignment->getRole()->isGlobal() && 'superadmin' === $assignment->getRole()->getSlug()) {
            throw new AdminException('Sám sobě roli superadmin odebrat nemůžeš.');
        }
        $user->getRoleAssignments()->removeElement($assignment);
        $this->em->flush();
        $this->audit->log('admin.role_revoke', $actor->getUuid(), $assignment->getRole()->getClientId(), ['target' => $user->getUuid(), 'role' => $assignment->toClaim()]);
    }

    private function hashedPassword(User $user, string $password): string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new AdminException(sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_PASSWORD_LENGTH));
        }

        return $this->hasher->hashPassword($user, $password);
    }

    private function validEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new AdminException('Neplatný e-mail.');
        }

        return $email;
    }

    private function validName(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new AdminException('Jméno je povinné (max. 120 znaků).');
        }

        return $name;
    }
}
