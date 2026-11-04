<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\AuditLogger;
use App\Device\DeviceTracker;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The user signs out their own devices from the account page. */
#[Route('/account/devices')]
final class DeviceController extends AbstractController
{
    public const CSRF_ID = 'account_devices';

    public function __construct(
        private readonly DeviceTracker $devices,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/{publicId}/logout', name: 'account_device_logout', methods: ['POST'], requirements: ['publicId' => '[0-9a-f-]{36}'])]
    public function logout(string $publicId, #[CurrentUser] User $user, Request $request): Response
    {
        $this->guard($request);
        $device = $this->devices->findDevice($user, $publicId) ?? throw new NotFoundHttpException();
        if ($device === $this->devices->currentDevice($request)) {
            return $this->redirectToRoute('app_logout'); // this browser: a normal logout
        }
        $this->devices->signOutDevice($device);
        $this->audit->log('device.logout', $user->getUuid(), null, ['device' => $device->getPublicId()]);
        $this->addFlash('success', 'Zařízení je odhlášené. Aplikace na něm ztratí přístup nejpozději do 10 minut.');

        return $this->redirectToRoute('app_home');
    }

    #[Route('/logout-others', name: 'account_devices_logout_others', methods: ['POST'])]
    public function logoutOthers(#[CurrentUser] User $user, Request $request): Response
    {
        $this->guard($request);
        $this->devices->signOutEverywhere($user, $this->devices->currentDevice($request));
        $this->audit->log('device.logout_others', $user->getUuid());
        $this->addFlash('success', 'Ostatní zařízení jsou odhlášená. Aplikace na nich ztratí přístup nejpozději do 10 minut.');

        return $this->redirectToRoute('app_home');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný bezpečnostní token, obnov stránku a zkus to znovu.');
        }
    }
}
