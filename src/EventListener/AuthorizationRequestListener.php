<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Audit\AuditLogger;
use App\Entity\User;
use App\Security\OAuthReturn;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * All apps are first-party club apps → the consent screen is skipped.
 * Login is enforced by access_control on /oauth/authorize; after login the user returns here.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final class AuthorizationRequestListener
{
    public function __construct(
        private readonly Security $security,
        private readonly AuditLogger $audit,
        private readonly OAuthReturn $return,
        private readonly RequestStack $requests,
    ) {
    }

    public function __invoke(AuthorizationRequestResolveEvent $event): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_DENIED);

            return;
        }

        $event->setUser($user);
        $event->resolveAuthorization(AuthorizationRequestResolveEvent::AUTHORIZATION_APPROVED);
        $request = $this->requests->getMainRequest();
        if (null !== $request && $request->hasSession()) {
            $this->return->clear($request->getSession());
        }

        $this->audit->log('authorize', $user->getUuid(), $event->getClient()->getIdentifier());
    }
}
