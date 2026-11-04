<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Device\DeviceTracker;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * Decorates the bundle's repository: refreshing fails once the device was signed out, and the
 * new refresh token stays linked to the same application sign-in. $inner is set in App\Kernel::process().
 */
final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private readonly RefreshTokenRepositoryInterface $inner,
        private readonly DeviceTracker $devices,
    ) {
    }

    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->inner->persistNewRefreshToken($refreshTokenEntity);
        $this->devices->refreshTokenIssued($refreshTokenEntity->getIdentifier());
    }

    /** @param string $tokenId */
    public function revokeRefreshToken($tokenId): void
    {
        $this->inner->revokeRefreshToken($tokenId);
    }

    /** Called by league before issuing tokens for a refresh. */
    public function isRefreshTokenRevoked($tokenId): bool
    {
        return $this->inner->isRefreshTokenRevoked($tokenId) || !$this->devices->resumeByRefreshToken($tokenId);
    }
}
