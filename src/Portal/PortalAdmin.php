<?php

declare(strict_types=1);

namespace App\Portal;

use App\Admin\AdminException;
use App\Audit\AuditLogger;
use App\Entity\ClientPortal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Admin side of the portal: whether and how an application is listed there. */
final class PortalAdmin
{
    public const MAX_DESCRIPTION = 160;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function find(string $clientId): ?ClientPortal
    {
        return $this->em->find(ClientPortal::class, $clientId);
    }

    /** An empty URL with the tile hidden removes the entry altogether. */
    public function update(string $clientId, string $launchUrl, ?string $description, bool $visible, bool $publicAccess, int $sortOrder, User $actor): void
    {
        $launchUrl = trim($launchUrl);
        $entry = $this->find($clientId);

        if ('' === $launchUrl && !$visible) {
            if (null !== $entry) {
                $this->em->remove($entry);
                $this->em->flush();
                $this->audit->log('admin.portal_update', $actor->getUuid(), $clientId, ['visible' => false]);
            }

            return;
        }

        $launchUrl = self::validUrl($launchUrl);
        $description = trim((string) $description);
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new AdminException(sprintf('Popis v rozcestníku může mít nejvýš %d znaků.', self::MAX_DESCRIPTION));
        }
        if ($sortOrder < -999 || $sortOrder > 999) {
            throw new AdminException('Pořadí zadej jako číslo od -999 do 999.');
        }

        $entry ??= new ClientPortal($clientId, $launchUrl);
        $entry->update($launchUrl, '' === $description ? null : $description, $visible, $publicAccess, $sortOrder);
        $this->em->persist($entry);
        $this->em->flush();
        $this->audit->log('admin.portal_update', $actor->getUuid(), $clientId, ['visible' => $visible, 'public' => $publicAccess]);
    }

    /** Called when the application itself is deleted. */
    public function delete(string $clientId): void
    {
        $entry = $this->find($clientId);
        if (null !== $entry) {
            $this->em->remove($entry);
            $this->em->flush();
        }
    }

    /** https only, http just for localhost / .test (same rule as redirect URIs). Pure. */
    public static function validUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $isLocal = \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.test');
        $scheme = $parts['scheme'] ?? '';
        if ('' === $host || !('https' === $scheme || ('http' === $scheme && $isLocal))) {
            throw new AdminException('Adresa aplikace musí začínat https:// (http jen pro localhost / .test).');
        }
        if (mb_strlen($url) > 255) {
            throw new AdminException('Adresa aplikace je příliš dlouhá (max. 255 znaků).');
        }

        return $url;
    }
}
