<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Audit\AuditLogger;
use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class TwoFactorAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TwoFactorAuthenticationEvents::FAILURE => 'onFailure',
            TwoFactorAuthenticationEvents::COMPLETE => 'onComplete',
        ];
    }

    public function onFailure(TwoFactorAuthenticationEvent $event): void
    {
        $this->audit->log('2fa.failed', $this->uuid($event));
    }

    public function onComplete(TwoFactorAuthenticationEvent $event): void
    {
        $this->audit->log('2fa.success', $this->uuid($event));
    }

    private function uuid(TwoFactorAuthenticationEvent $event): ?string
    {
        $user = $event->getToken()->getUser();

        return $user instanceof User ? $user->getUuid() : null;
    }
}
