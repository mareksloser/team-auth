<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminException;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Common base: CSRF check, current admin, AdminException → flash message. */
#[IsGranted('ROLE_SUPERADMIN')]
abstract class AdminController extends AbstractController
{
    public const CSRF_ID = 'admin';

    protected function actor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    protected function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný bezpečnostní token, obnov stránku a zkus to znovu.');
        }
    }

    /**
     * Runs the action and redirects; input errors are shown as a flash message.
     *
     * @param callable(): void $action
     */
    protected function handle(Request $request, callable $action, string $success, string $route, array $params = []): RedirectResponse
    {
        $this->guard($request);
        try {
            $action();
            $this->addFlash('success', $success);
        } catch (AdminException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute($route, $params);
    }
}
