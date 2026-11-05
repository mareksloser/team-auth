<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How one application (OAuth client) appears in the club portal. A client without a row
 * (backends, the portal itself) is never listed there.
 */
#[ORM\Entity]
#[ORM\Table(name: 'client_portal')]
class ClientPortal
{
    #[ORM\Id]
    #[ORM\Column(name: 'client_id', length: 80)]
    private string $clientId;

    /** Where the tile leads, e.g. https://mvr.example.com/ */
    #[ORM\Column(name: 'launch_url', length: 255)]
    private string $launchUrl;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $visible = true;

    /** Listed for every signed-in user, also without a role (e.g. a public gallery). */
    #[ORM\Column(name: 'public_access', options: ['default' => false])]
    private bool $publicAccess = false;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $clientId, string $launchUrl)
    {
        $this->clientId = $clientId;
        $this->launchUrl = $launchUrl;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getClientId(): string { return $this->clientId; }
    public function getLaunchUrl(): string { return $this->launchUrl; }
    public function getDescription(): ?string { return $this->description; }
    public function isVisible(): bool { return $this->visible; }
    public function isPublicAccess(): bool { return $this->publicAccess; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function update(string $launchUrl, ?string $description, bool $visible, bool $publicAccess, int $sortOrder): void
    {
        $this->launchUrl = $launchUrl;
        $this->description = $description;
        $this->visible = $visible;
        $this->publicAccess = $publicAccess;
        $this->sortOrder = $sortOrder;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
