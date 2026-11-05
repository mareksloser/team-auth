<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AppRole;
use App\Entity\TeamCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function __construct(private readonly EntityManagerInterface $em)
    {
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
}
