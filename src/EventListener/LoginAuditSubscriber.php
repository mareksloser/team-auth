<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Audit\AuditLogger;
use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
        ];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        // interactive logins only, not every bearer-token request
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $user = $event->getUser();
        $this->audit->log('login', $user instanceof User ? $user->getUuid() : null);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        $this->audit->log('login_failed', null, null, [
            'email' => (string) $event->getRequest()->request->get('email', ''),
        ]);
    }
}
