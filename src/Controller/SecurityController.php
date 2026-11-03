<?php

declare(strict_types=1);

namespace App\Controller;

use App\Branding\BrandingService;
use App\Entity\User;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $utils, Request $request, ClientManagerInterface $clients, BrandingService $branding): Response
    {
        $client = $this->signingInTo($request, $clients);
        $brand = null !== $client ? $branding->find($client->getIdentifier()) : null;

        return $this->render('security/login.html.twig', [
            'last_username' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
            'client' => $client,
            'brand' => $brand,
            'brandVars' => BrandingService::cssVariables($brand),
        ]);
    }

    /**
     * The application the user is signing in to: the firewall saved the /oauth/authorize URL
     * as the target path before redirecting here, so its client_id tells us which one.
     */
    private function signingInTo(Request $request, ClientManagerInterface $clients): ?ClientInterface
    {
        $target = $request->hasSession() ? $this->getTargetPath($request->getSession(), 'main') : null;
        if (null === $target || !str_contains((string) parse_url($target, \PHP_URL_PATH), '/oauth/authorize')) {
            return null;
        }
        parse_str((string) parse_url($target, \PHP_URL_QUERY), $query);
        $clientId = $query['client_id'] ?? null;
        $client = \is_string($clientId) && '' !== $clientId ? $clients->find($clientId) : null;

        return null !== $client && $client->isActive() ? $client : null;
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Zachytí firewall – viz OidcLogoutListener.');
    }

    #[Route('/', name: 'app_home')]
    public function home(#[CurrentUser] User $user): Response
    {
        return $this->render('security/home.html.twig', ['user' => $user]);
    }
}
