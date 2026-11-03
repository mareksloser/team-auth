<?php

declare(strict_types=1);

namespace App\Branding;

use App\Admin\AdminException;
use App\Audit\AuditLogger;
use App\Entity\ClientBranding;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Per-application login page branding: logo and colours. */
final class BrandingService
{
    public const MAX_LOGO_BYTES = 512 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LogoStorage $storage,
        private readonly AuditLogger $audit,
    ) {
    }

    public function find(string $clientId): ?ClientBranding
    {
        return $this->em->find(ClientBranding::class, $clientId);
    }

    public function updateColors(string $clientId, ?string $accent, ?string $background, User $actor): void
    {
        try {
            $accent = Color::normalize($accent);
            $background = Color::normalize($background);
        } catch (\InvalidArgumentException) {
            throw new AdminException('Barvu zadej jako hex kód, např. #166534.');
        }

        $branding = $this->findOrCreate($clientId);
        $branding->setColors($accent, $background);
        $this->save($branding);
        $this->audit->log('admin.branding_update', $actor->getUuid(), $clientId, ['accent' => $accent, 'background' => $background]);
    }

    public function uploadLogo(string $clientId, string $bytes, User $actor): void
    {
        if ('' === $bytes) {
            throw new AdminException('Soubor je prázdný.');
        }
        if (\strlen($bytes) > self::MAX_LOGO_BYTES) {
            throw new AdminException(sprintf('Logo může mít nejvýš %d kB.', self::MAX_LOGO_BYTES / 1024));
        }
        $mime = LogoType::detect($bytes);
        if (null === $mime) {
            throw new AdminException('Podporované formáty loga jsou PNG, JPEG, WebP a SVG.');
        }

        $branding = $this->findOrCreate($clientId);
        $previous = $branding->getLogoFile();
        $file = sprintf('%s-%s.%s', preg_replace('/[^a-z0-9_-]/i', '', $clientId), substr(hash('sha256', $bytes), 0, 12), LogoType::EXTENSIONS[$mime]);

        $this->storage->write($file, $bytes);
        $branding->setLogo($file, $mime);
        $this->save($branding);
        if ($previous !== $file) {
            $this->storage->delete($previous);
        }
        $this->audit->log('admin.branding_logo', $actor->getUuid(), $clientId, ['mime' => $mime]);
    }

    public function removeLogo(string $clientId, User $actor): void
    {
        $branding = $this->find($clientId);
        if (null === $branding || !$branding->hasLogo()) {
            return;
        }
        $this->storage->delete($branding->getLogoFile());
        $branding->setLogo(null, null);
        $this->save($branding);
        $this->audit->log('admin.branding_logo_remove', $actor->getUuid(), $clientId);
    }

    /** Called when the application itself is deleted. */
    public function delete(string $clientId): void
    {
        $branding = $this->find($clientId);
        if (null === $branding) {
            return;
        }
        $this->storage->delete($branding->getLogoFile());
        $this->em->remove($branding);
        $this->em->flush();
    }

    /**
     * CSS custom properties for the login page; empty when the application has no colours set.
     *
     * @return array<string, string>
     */
    public static function cssVariables(?ClientBranding $branding): array
    {
        $vars = [];
        if (null !== ($accent = $branding?->getAccentColor())) {
            $vars['--accent'] = $accent;
            $vars['--accent-ink'] = Color::readableInk($accent);
        }
        if (null !== ($background = $branding?->getBackgroundColor())) {
            $vars['--bg'] = $background;
            $vars['--bg-ink'] = Color::readableInk($background);
        }

        return $vars;
    }

    private function findOrCreate(string $clientId): ClientBranding
    {
        return $this->find($clientId) ?? new ClientBranding($clientId);
    }

    private function save(ClientBranding $branding): void
    {
        if ($branding->isEmpty()) {
            if ($this->em->contains($branding)) {
                $this->em->remove($branding);
            }
        } else {
            $this->em->persist($branding);
        }
        $this->em->flush();
    }
}
