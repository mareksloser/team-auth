<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Device\DeviceTracker;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/** Decorates the bundle's repository to start/resume device tracking. $inner is set in App\Kernel::process(). */
final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private readonly AuthCodeRepositoryInterface $inner,
        private readonly DeviceTracker $devices,
    ) {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return $this->inner->getNewAuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->inner->persistNewAuthCode($authCodeEntity);
        $userUuid = $authCodeEntity->getUserIdentifier();
        if (null !== $userUuid && '' !== $userUuid) {
            $this->devices->grantStarted($authCodeEntity->getIdentifier(), $userUuid, $authCodeEntity->getClient()->getIdentifier());
        }
    }

    /** @param string $codeId */
    public function revokeAuthCode($codeId): void
    {
        $this->inner->revokeAuthCode($codeId);
    }

    /** Called by league before issuing tokens for the code. */
    public function isAuthCodeRevoked($codeId): bool
    {
        return $this->inner->isAuthCodeRevoked($codeId) || !$this->devices->resumeByAuthCode($codeId);
    }
}
