<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_user', columns: ['user_uuid'])]
#[ORM\Index(name: 'idx_audit_created', columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'bigint')]
    private int|string|null $id = null;

    #[ORM\Column(name: 'user_uuid', length: 36, nullable: true)]
    private ?string $userUuid;

    #[ORM\Column(name: 'client_id', length: 80, nullable: true)]
    private ?string $clientId;

    #[ORM\Column(length: 50)]
    private string $action;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $context */
    public function __construct(string $action, ?string $userUuid, ?string $clientId, ?string $ip, array $context = [])
    {
        $this->action = $action;
        $this->userUuid = $userUuid;
        $this->clientId = $clientId;
        $this->ip = $ip;
        $this->context = $context;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserUuid(): ?string { return $this->userUuid; }
    public function getClientId(): ?string { return $this->clientId; }
    public function getAction(): string { return $this->action; }
    public function getIp(): ?string { return $this->ip; }
    /** @return array<string, mixed> */
    public function getContext(): array { return $this->context; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
