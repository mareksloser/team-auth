<?php

declare(strict_types=1);

namespace App\Controller;

use App\Branding\LoginBranding;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $utils, LoginBranding $branding): Response
    {
        $current = $branding->current();

        return $this->render('security/login.html.twig', [
            'last_username' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
            'client' => $current['client'],
            'brand' => $current['brand'],
            'brandVars' => $current['vars'],
        ]);
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
