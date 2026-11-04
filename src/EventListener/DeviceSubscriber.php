<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Device\DeviceTracker;
use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Connects the browser session on the auth server with DeviceTracker:
 * registers the device after a full sign-in, ends sessions of devices that were signed out
 * elsewhere, and signs the device out on logout.
 */
final class DeviceSubscriber implements EventSubscriberInterface
{
    private ?Cookie $pendingCookie = null;

    public function __construct(
        private readonly DeviceTracker $devices,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLogin',
            KernelEvents::REQUEST => ['onRequest', 4],   // after the firewall (8)
            KernelEvents::RESPONSE => 'onResponse',
            LogoutEvent::class => ['onLogout', 64],      // before the session is invalidated
        ];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        // The password step of a two-factor login doesn't count yet; the code step fires this event again.
        if ('main' !== $event->getFirewallName() || $event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            return;
        }
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->pendingCookie = $this->devices->startDevice($user, $event->getRequest());
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'main' !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }
        $token = $this->security->getToken();
        $user = $token?->getUser();
        if (!$user instanceof User || $token instanceof TwoFactorTokenInterface) {
            return;
        }

        $device = $this->devices->currentDevice($request);
        if (null === $device) {
            // Session from before devices were tracked: register it now.
            $this->pendingCookie = $this->devices->startDevice($user, $request);

            return;
        }
        if ($device->isRevoked() || $device->getUser()->getId() !== $user->getId()) {
            // Signed out from another device or by an admin.
            $event->setResponse($this->security->logout(false) ?? new RedirectResponse($this->urls->generate('app_login')));

            return;
        }
        $this->devices->touch($device, $request);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (null !== $this->pendingCookie && $event->isMainRequest()) {
            $event->getResponse()->headers->setCookie($this->pendingCookie);
            $this->pendingCookie = null;
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        if ($event->getRequest()->hasSession()) {
            $this->devices->signOutCurrentDevice($event->getRequest());
        }
    }
}
