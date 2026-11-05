<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Portal\AppCatalog;
use App\Portal\PortalApp;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bearer-token API for the club portal. The token must carry the `apps` scope
 * (see security.yaml); any client allowed to ask for it may call this.
 */
final class PortalApiController
{
    public function __construct(private readonly AppCatalog $catalog)
    {
    }

    #[Route('/api/apps', name: 'api_apps', methods: ['GET'])]
    public function apps(#[CurrentUser] User $user): JsonResponse
    {
        if (!$user->isActive()) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        $response = new JsonResponse([
            'apps' => array_map(static fn (PortalApp $app): array => $app->toArray(), $this->catalog->forUser($user)),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
