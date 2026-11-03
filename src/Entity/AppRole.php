<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Role patří buď konkrétní aplikaci (client_id), nebo je globální (client_id = NULL, např. superadmin).
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_roles')]
#[ORM\UniqueConstraint(name: 'uniq_client_role', columns: ['client_id', 'slug'])]
class AppRole
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'client_id', length: 80, nullable: true)]
    private ?string $clientId;

    #[ORM\Column(length: 50)]
    private string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description;

    public function __construct(?string $clientId, string $slug, ?string $description = null)
    {
        $this->clientId = $clientId;
        $this->slug = $slug;
        $this->description = $description;
    }

    public function getId(): ?int { return $this->id; }
    public function getClientId(): ?string { return $this->clientId; }
    public function getSlug(): string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function isGlobal(): bool { return null === $this->clientId; }
}
