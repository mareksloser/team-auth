<?php

declare(strict_types=1);

namespace App\Portal;

use App\Branding\BrandingService;
use App\Entity\ClientBranding;
use App\Entity\ClientPortal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;

/**
 * Which applications a user sees in the club portal. Permissions are not duplicated here:
 * an application is listed when the user has any of its roles (the same roles that go into
 * its tokens), when it is marked public, or for a superadmin.
 */
final class AppCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientManagerInterface $clients,
        private readonly BrandingService $branding,
        private readonly string $issuer,
    ) {
    }

    /** @return list<PortalApp> */
    public function forUser(User $user): array
    {
        $entries = $this->em->getRepository(ClientPortal::class)->findBy(['visible' => true]);

        $names = [];
        foreach ($this->clients->list(null) as $client) {
            if ($client->isActive()) {
                $names[$client->getIdentifier()] = $client->getName();
            }
        }

        $grants = [];
        foreach ($user->getRoleAssignments() as $assignment) {
            $role = $assignment->getRole();
            if ($role->isGlobal()) {
                continue;
            }
            $team = $assignment->getTeam();
            $grants[(string) $role->getClientId()][] = [
                'role' => $role->getSlug(),
                'team' => $team?->getSlug(),
                'teamName' => $team?->getName(),
            ];
        }

        $brands = [];
        foreach ($entries as $entry) {
            $brands[$entry->getClientId()] = $this->branding->find($entry->getClientId());
        }

        return self::select($entries, $names, $grants, $user->isSuperadmin(), $brands, rtrim($this->issuer, '/'));
    }

    /**
     * Pure selection and ordering, testable without Doctrine.
     *
     * @param ClientPortal[]                                                               $entries visible portal entries
     * @param array<string, string>                                                        $names   active clients: client_id => name
     * @param array<string, list<array{role: string, team: ?string, teamName: ?string}>> $grants  user's roles by client_id
     * @param array<string, ?ClientBranding>                                               $brands  by client_id
     *
     * @return list<PortalApp>
     */
    public static function select(array $entries, array $names, array $grants, bool $superadmin, array $brands, string $issuer): array
    {
        $apps = [];
        foreach ($entries as $entry) {
            $id = $entry->getClientId();
            if (!$entry->isVisible() || !isset($names[$id])) {
                continue; // hidden, deleted or deactivated application
            }
            $roles = self::uniqueRoles($grants[$id] ?? []);
            $access = match (true) {
                [] !== $roles => PortalApp::ACCESS_ROLE,
                $entry->isPublicAccess() => PortalApp::ACCESS_PUBLIC,
                $superadmin => PortalApp::ACCESS_SUPERADMIN,
                default => null,
            };
            if (null === $access) {
                continue;
            }
            $brand = $brands[$id] ?? null;
            $apps[] = [$entry->getSortOrder(), $names[$id], new PortalApp(
                $id,
                $names[$id],
                $entry->getDescription(),
                $entry->getLaunchUrl(),
                null !== $brand && $brand->hasLogo()
                    ? sprintf('%s/branding/%s/logo?v=%s', $issuer, rawurlencode($id), rawurlencode((string) $brand->getLogoFile()))
                    : null,
                $brand?->getAccentColor(),
                $roles,
                $access,
            )];
        }

        usort($apps, static fn (array $a, array $b): int => [$a[0], mb_strtolower($a[1])] <=> [$b[0], mb_strtolower($b[1])]);

        return array_map(static fn (array $row): PortalApp => $row[2], $apps);
    }

    /**
     * @param list<array{role: string, team: ?string, teamName: ?string}> $roles
     *
     * @return list<array{role: string, team: ?string, teamName: ?string}>
     */
    private static function uniqueRoles(array $roles): array
    {
        $unique = [];
        foreach ($roles as $role) {
            $unique[$role['role'].'@'.($role['team'] ?? '')] = $role;
        }
        ksort($unique);

        return array_values($unique);
    }
}
