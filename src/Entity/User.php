<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use App\Security\TwoFactor\BackupCodes;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface
{
    /** Standard TOTP parameters, compatible with Google Authenticator, Microsoft Authenticator, Aegis, 1Password… */
    public const TOTP_PERIOD = 30;
    public const TOTP_DIGITS = 6;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** Public identifier = `sub` claim. Never changes. */
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

    /** Encrypted by TotpSecretCipher; null = two-factor authentication is off. */
    #[ORM\Column(name: 'totp_secret', length: 255, nullable: true)]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column(name: 'totp_enabled_at', nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /** @var list<string>|null SHA-256 hashes of unused backup codes */
    #[ORM\Column(name: 'totp_backup_codes', type: 'json', nullable: true)]
    private ?array $backupCodeHashes = null;

    /** Decrypted secret, set by TotpSecretLoader after loading; never persisted or serialized. */
    private ?string $totpSecret = null;

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

    /** Roles for the IdP itself (admin). App roles go into the token as the `roles` claim. */
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

    /* ---- Two-factor authentication (TOTP + backup codes) ---- */

    public function isTotpAuthenticationEnabled(): bool
    {
        // Based on the stored value, not the decrypted one: if decryption fails, login fails too (fail closed).
        return null !== $this->totpSecretEncrypted;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecretEncrypted) {
            return null;
        }
        if (null === $this->totpSecret) {
            throw new \RuntimeException('TOTP secret is not available (check TOTP_ENCRYPTION_KEY).');
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, self::TOTP_PERIOD, self::TOTP_DIGITS);
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    public function getTotpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    /** Called by TotpSecretLoader right after Doctrine loads the user. */
    public function unlockTotpSecret(string $plainSecret): void
    {
        $this->totpSecret = $plainSecret;
    }

    /** @param list<string> $backupCodeHashes */
    public function enableTotp(string $encryptedSecret, string $plainSecret, array $backupCodeHashes): void
    {
        $this->totpSecretEncrypted = $encryptedSecret;
        $this->totpSecret = $plainSecret;
        $this->totpEnabledAt = new \DateTimeImmutable();
        $this->backupCodeHashes = $backupCodeHashes;
    }

    public function disableTotp(): void
    {
        $this->totpSecretEncrypted = null;
        $this->totpSecret = null;
        $this->totpEnabledAt = null;
        $this->backupCodeHashes = null;
    }

    /** @param list<string> $hashes */
    public function replaceBackupCodes(array $hashes): void
    {
        $this->backupCodeHashes = $hashes;
    }

    public function remainingBackupCodes(): int
    {
        return \count($this->backupCodeHashes ?? []);
    }

    public function isBackupCode(string $code): bool
    {
        $hash = BackupCodes::hash($code);
        foreach ($this->backupCodeHashes ?? [] as $stored) {
            if (hash_equals($stored, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(string $code): void
    {
        $hash = BackupCodes::hash($code);
        $this->backupCodeHashes = array_values(array_filter(
            $this->backupCodeHashes ?? [],
            static fn (string $stored): bool => !hash_equals($stored, $hash),
        ));
    }

    /**
     * The user ends up in the session: keep the decrypted TOTP secret out of it and store only a
     * checksum of the password hash (Symfony compares it to detect password changes). Both are
     * restored when the firewall reloads the user from the database.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['totpSecret']);
        $data['password'] = hash('crc32c', $this->password);

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            $this->{$property} = $value;
        }
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
