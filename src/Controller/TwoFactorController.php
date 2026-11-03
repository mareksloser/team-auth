<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminException;
use App\Entity\User;
use App\Security\TwoFactor\BackupCodes;
use App\Security\TwoFactor\TwoFactorSetup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The user's own two-factor settings (account page). */
#[Route('/account/2fa')]
final class TwoFactorController extends AbstractController
{
    private const CSRF_ID = 'account_2fa';

    public function __construct(private readonly TwoFactorSetup $setup)
    {
    }

    #[Route('', name: 'account_2fa', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, Request $request): Response
    {
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->render('security/2fa_settings.html.twig', ['user' => $user]);
        }
        $secret = $this->setup->pendingSecret($request->getSession());

        return $this->noStore($this->render('security/2fa_setup.html.twig', [
            'qr' => $this->setup->qrCodeDataUri($user, $secret),
            'secretGroups' => str_split($secret, 4),
        ]));
    }

    #[Route('/enable', name: 'account_2fa_enable', methods: ['POST'])]
    public function enable(#[CurrentUser] User $user, Request $request): Response
    {
        $this->guard($request);
        try {
            $codes = $this->setup->confirm($user, $request->getSession(), (string) $request->request->get('code'));
        } catch (AdminException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('account_2fa');
        }

        return $this->showBackupCodes($codes, true);
    }

    #[Route('/backup-codes', name: 'account_2fa_backup_codes', methods: ['POST'])]
    public function backupCodes(#[CurrentUser] User $user, Request $request): Response
    {
        $this->guard($request);
        try {
            return $this->showBackupCodes($this->setup->regenerateBackupCodes($user, (string) $request->request->get('password')), false);
        } catch (AdminException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('account_2fa');
        }
    }

    #[Route('/disable', name: 'account_2fa_disable', methods: ['POST'])]
    public function disable(#[CurrentUser] User $user, Request $request): Response
    {
        $this->guard($request);
        try {
            $this->setup->disable($user, (string) $request->request->get('password'));
            $this->addFlash('success', 'Dvoufázové ověření je vypnuté.');
        } catch (AdminException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('account_2fa');
    }

    /** @param list<string> $codes */
    private function showBackupCodes(array $codes, bool $justEnabled): Response
    {
        return $this->noStore($this->render('security/2fa_backup_codes.html.twig', [
            'codes' => $codes,
            'justEnabled' => $justEnabled,
            'count' => BackupCodes::COUNT,
        ]));
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný bezpečnostní token, obnov stránku a zkus to znovu.');
        }
    }

    /** Pages with secrets or backup codes must not end up in the browser cache. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
