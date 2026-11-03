<?php

declare(strict_types=1);

namespace App\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/** Přidá do odpovědi /oauth/token `id_token`, pokud klient žádal scope `openid`. */
final class IdTokenResponse extends BearerTokenResponse
{
    public function __construct(
        private readonly ClaimsProvider $claims,
        private readonly KeySet $keys,
        private readonly string $issuer,
    ) {
    }

    /** @return array<string, mixed> */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(static fn (ScopeEntityInterface $s): string => $s->getIdentifier(), $accessToken->getScopes());
        $sub = (string) ($accessToken->getUserIdentifier() ?? '');

        if ('' === $sub || !\in_array('openid', $scopes, true)) {
            return [];
        }

        $clientId = $accessToken->getClient()->getIdentifier();
        $claims = $this->claims->forSubject($sub, $clientId, $scopes) ?? [];

        return [
            'id_token' => Jwt::sign(
                key: $this->privateKey,
                keyId: $this->keys->keyId(),
                issuer: $this->issuer,
                audience: $clientId,
                subject: $sub,
                expiresAt: new \DateTimeImmutable('+1 hour'),
                claims: $claims,
            ),
        ];
    }
}
