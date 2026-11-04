<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One sign-in to an application (OAuth client) on a device. Follows the refresh token through
 * rotation, so revoking the grant stops the application from getting new access tokens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_grants')]
#[ORM\Index(name: 'idx_grant_auth_code', columns: ['auth_code_id'])]
#[ORM\Index(name: 'idx_grant_refresh_token', columns: ['refresh_token_id'])]
class AppGrant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** null for sign-ins whose browser wasn't tracked (before this feature). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'device_id', nullable: true, onDelete: 'CASCADE')]
    private ?UserDevice $device;

    #[ORM\Column(name: 'client_id', length: 80)]
    private string $clientId;

    #[ORM\Column(name: 'auth_code_id', length: 80, nullable: true)]
    private ?string $authCodeId;

    /** Current refresh token of the chain (league rotates it on every refresh). */
    #[ORM\Column(name: 'refresh_token_id', length: 80, nullable: true)]
    private ?string $refreshTokenId = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, ?UserDevice $device, string $clientId, string $authCodeId, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->device = $device;
        $this->clientId = $clientId;
        $this->authCodeId = $authCodeId;
        $this->createdAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getDevice(): ?UserDevice { return $this->device; }
    public function getClientId(): string { return $this->clientId; }
    public function getRefreshTokenId(): ?string { return $this->refreshTokenId; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }

    /** Revoked itself, or the device it belongs to was signed out. */
    public function isRevoked(): bool
    {
        return null !== $this->revokedAt || (null !== $this->device && $this->device->isRevoked());
    }

    public function useRefreshToken(string $refreshTokenId, \DateTimeImmutable $now): void
    {
        $this->authCodeId = null;
        $this->refreshTokenId = $refreshTokenId;
        $this->lastSeenAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
