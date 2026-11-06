<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppRole;
use App\Entity\TeamCategory;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bearer-token API: the club's teams and the role catalogue of the calling application.
 * Lets an app offer "who may see this" choices (e.g. the club portal's link editor)
 * without keeping its own copy of teams and roles.
 */
final class DirectoryApiController
{
    public const ADMIN_ROLE = 'admin';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/api/directory', name: 'api_directory', methods: ['GET'])]
    public function directory(#[CurrentUser] User $user, TokenStorageInterface $tokens): JsonResponse
    {
        $token = $tokens->getToken();
        $clientId = $token instanceof OAuth2Token ? $token->getOAuthClientId() : null;
        if (!$user->isActive() || null === $clientId) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        $teams = array_map(
            static fn (TeamCategory $t): array => ['slug' => $t->getSlug(), 'name' => $t->getName()],
            $this->em->getRepository(TeamCategory::class)->findBy([], ['name' => 'ASC']),
        );
        $roles = array_map(
            static fn (AppRole $r): array => ['slug' => $r->getSlug(), 'description' => $r->getDescription()],
            $this->em->getRepository(AppRole::class)->findBy(['clientId' => $clientId], ['slug' => 'ASC']),
        );

        $response = new JsonResponse(['clientId' => $clientId, 'teams' => $teams, 'roles' => $roles]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Club accounts matching ?q= (name or e-mail), so an app can link its own records
     * (players, coaches) to them. Only for the app's admins (role `admin` in the calling
     * application) and superadmins: it lists other people's e-mails.
     */
    #[Route('/api/directory/users', name: 'api_directory_users', methods: ['GET'])]
    public function users(#[CurrentUser] User $user, TokenStorageInterface $tokens, Request $request): JsonResponse
    {
        $token = $tokens->getToken();
        $clientId = $token instanceof OAuth2Token ? $token->getOAuthClientId() : null;
        if (!$user->isActive() || null === $clientId) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }
        if (!$user->isSuperadmin() && !self::isAppAdmin($user, $clientId)) {
            return new JsonResponse(['error' => 'access_denied'], 403);
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2) {
            return new JsonResponse(['users' => []]);
        }

        $users = array_map(static fn (User $u): array => [
            'sub' => $u->getUuid(),
            'name' => $u->getName(),
            'email' => $u->getEmail(),
            'active' => $u->isActive(),
        ], $this->users->search($query, 20));

        $response = new JsonResponse(['users' => $users]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private static function isAppAdmin(User $user, string $clientId): bool
    {
        foreach ($user->getRoleAssignments() as $assignment) {
            $role = $assignment->getRole();
            if ($role->getClientId() === $clientId && self::ADMIN_ROLE === $role->getSlug()) {
                return true;
            }
        }

        return false;
    }
}
