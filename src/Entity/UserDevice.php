<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A browser the user signed in from. Recognised again by a long-lived device cookie,
 * so signing in repeatedly from the same browser doesn't create new rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_devices')]
#[ORM\Index(name: 'idx_device_user_hash', columns: ['user_id', 'device_hash'])]
#[ORM\Index(name: 'idx_device_last_seen', columns: ['last_seen_at'])]
class UserDevice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'public_id', length: 36, unique: true)]
    private string $publicId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** SHA-256 of the device cookie value; the cookie itself is never stored. */
    #[ORM\Column(name: 'device_hash', length: 64)]
    private string $deviceHash;

    #[ORM\Column(name: 'user_agent', length: 255)]
    private string $userAgent;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_seen_at')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $deviceHash, string $userAgent, ?string $ip, \DateTimeImmutable $now)
    {
        $this->publicId = Uuid::v7()->toRfc4122();
        $this->user = $user;
        $this->deviceHash = $deviceHash;
        $this->userAgent = mb_substr($userAgent, 0, 255);
        $this->ip = $ip;
        $this->createdAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getPublicId(): string { return $this->publicId; }
    public function getUser(): User { return $this->user; }
    public function getUserAgent(): string { return $this->userAgent; }
    public function getIp(): ?string { return $this->ip; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isRevoked(): bool { return null !== $this->revokedAt; }

    public function seen(string $userAgent, ?string $ip, \DateTimeImmutable $now): void
    {
        $this->userAgent = mb_substr($userAgent, 0, 255);
        $this->ip = $ip;
        $this->lastSeenAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}
