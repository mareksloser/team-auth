<?php

declare(strict_types=1);

namespace App\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * Decorates the bundle's repository: creates our AccessToken (with claims) and leaves
 * persistence and revocation to the original implementation. $inner is set in App\Kernel::process().
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    /** Claims also included in the access token (so APIs don't need to call /userinfo). */
    private const ACCESS_TOKEN_CLAIMS = ['name', 'email', 'roles', 'global_roles', 'teams'];

    public function __construct(
        private readonly AccessTokenRepositoryInterface $inner,
        private readonly ClaimsProvider $claims,
        private readonly KeySet $keys,
        private readonly string $issuer,
    ) {
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @param string|int|null        $userIdentifier
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessToken();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }

        $claims = [];
        if (null !== $userIdentifier && '' !== (string) $userIdentifier) {
            $token->setUserIdentifier((string) $userIdentifier);

            $scopeIds = array_map(static fn (ScopeEntityInterface $s): string => $s->getIdentifier(), $scopes);
            $all = $this->claims->forSubject((string) $userIdentifier, $clientEntity->getIdentifier(), $scopeIds);
            if (null === $all) {
                // a deactivated user gets no token, not even via refresh_token
                throw OAuthServerException::accessDenied('Účet neexistuje nebo je deaktivovaný.');
            }
            $claims = array_intersect_key($all, array_flip(self::ACCESS_TOKEN_CLAIMS));
        }

        $token->withJwtContext($this->issuer, $this->keys->keyId(), $claims);

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->inner->persistNewAccessToken($accessTokenEntity);
    }

    /** @param string $tokenId */
    public function revokeAccessToken($tokenId): void
    {
        $this->inner->revokeAccessToken($tokenId);
    }

    /** @param string $tokenId */
    public function isAccessTokenRevoked($tokenId): bool
    {
        return $this->inner->isAccessTokenRevoked($tokenId);
    }
}
