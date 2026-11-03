<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requests,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function log(string $action, ?string $userUuid = null, ?string $clientId = null, array $context = []): void
    {
        $ip = $this->requests->getCurrentRequest()?->getClientIp();
        $this->em->persist(new AuditLog($action, $userUuid, $clientId, $ip, $context));
        $this->em->flush();
    }
}
