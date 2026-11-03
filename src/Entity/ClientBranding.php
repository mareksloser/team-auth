<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Look of the login page when signing in to one application (OAuth client). */
#[ORM\Entity]
#[ORM\Table(name: 'client_branding')]
class ClientBranding
{
    #[ORM\Id]
    #[ORM\Column(name: 'client_id', length: 80)]
    private string $clientId;

    /** File name in LogoStorage; it contains a content hash, so it doubles as a cache-busting version. */
    #[ORM\Column(name: 'logo_file', length: 120, nullable: true)]
    private ?string $logoFile = null;

    #[ORM\Column(name: 'logo_mime', length: 40, nullable: true)]
    private ?string $logoMime = null;

    #[ORM\Column(name: 'accent_color', length: 7, nullable: true)]
    private ?string $accentColor = null;

    #[ORM\Column(name: 'background_color', length: 7, nullable: true)]
    private ?string $backgroundColor = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $clientId)
    {
        $this->clientId = $clientId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getClientId(): string { return $this->clientId; }
    public function getLogoFile(): ?string { return $this->logoFile; }
    public function getLogoMime(): ?string { return $this->logoMime; }
    public function getAccentColor(): ?string { return $this->accentColor; }
    public function getBackgroundColor(): ?string { return $this->backgroundColor; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function hasLogo(): bool
    {
        return null !== $this->logoFile;
    }

    public function setColors(?string $accent, ?string $background): void
    {
        $this->accentColor = $accent;
        $this->backgroundColor = $background;
        $this->touch();
    }

    public function setLogo(?string $file, ?string $mime): void
    {
        $this->logoFile = $file;
        $this->logoMime = $mime;
        $this->touch();
    }

    public function isEmpty(): bool
    {
        return null === $this->logoFile && null === $this->accentColor && null === $this->backgroundColor;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
