<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Security\OAuthReturn;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Sends the user back to the application's authorize request after a full sign-in. */
final class OAuthReturnSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OAuthReturn $return)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 16],   // before the firewall (8) redirects to /login
            LoginSuccessEvent::class => ['onLogin', -64], // after the success handlers built their response
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->isMethod('GET') && OAuthReturn::AUTHORIZE_PATH === $request->getPathInfo()) {
            $this->return->remember($request);
        }
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        // The password step of a two-factor login isn't the end yet.
        if ('main' !== $event->getFirewallName() || $event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            return;
        }
        $request = $event->getRequest();
        $url = $request->hasSession() ? $this->return->pending($request->getSession()) : null;
        if (null !== $url) {
            $event->setResponse(new RedirectResponse($url));
        }
    }
}
