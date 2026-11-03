<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Audit\AuditLogger;
use App\Entity\User;
use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Všechny aplikace jsou klubové (first-party) → souhlasovou obrazovku přeskakujeme.
 * Přihlášení vynutí access_control na /oauth/authorize, po loginu se uživatel vrátí sem.
 */
#[AsEventListener(event: OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE)]
final class AuthorizationRequestListener
{
    public function __construct(
        private readonly Security $security,
        private readonly AuditLogger $audit,
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

        $this->audit->log('authorize', $user->getUuid(), $event->getClient()->getIdentifier());
    }
}
