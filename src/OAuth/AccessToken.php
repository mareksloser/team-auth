<?php

declare(strict_types=1);

namespace App\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Access token s claimy iss, kid, roles, teams…
 * Přepisuje serializaci z AccessTokenTrait (toString() v league v9, __toString() v v8).
 */
final class AccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    private string $issuer = '';
    private ?string $keyId = null;
    /** @var array<string, mixed> */
    private array $extraClaims = [];

    /** @param array<string, mixed> $claims */
    public function withJwtContext(string $issuer, ?string $keyId, array $claims): void
    {
        $this->issuer = $issuer;
        $this->keyId = $keyId;
        $this->extraClaims = $claims;
    }

    public function toString(): string
    {
        return $this->buildJwt();
    }

    public function __toString(): string
    {
        return $this->buildJwt();
    }

    private function buildJwt(): string
    {
        $clientId = $this->getClient()->getIdentifier();
        $userId = (string) ($this->getUserIdentifier() ?? '');
        $scopes = array_values(array_map(
            static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $this->getScopes(),
        ));

        return Jwt::sign(
            key: $this->privateKey,
            keyId: $this->keyId,
            issuer: $this->issuer,
            audience: $clientId,
            subject: '' !== $userId ? $userId : $clientId,
            expiresAt: $this->getExpiryDateTime(),
            claims: ['scopes' => $scopes, 'client_id' => $clientId] + $this->extraClaims,
            tokenId: $this->getIdentifier(),
        );
    }
}
