<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\OAuth\ClaimsProvider;
use App\OAuth\KeySet;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class OidcController
{
    /** @param string[] $supportedScopes */
    public function __construct(
        private readonly ClaimsProvider $claims,
        private readonly KeySet $keys,
        private readonly string $issuer,
        private readonly array $supportedScopes,
    ) {
    }

    #[Route('/.well-known/openid-configuration', name: 'oidc_discovery', methods: ['GET'])]
    public function discovery(): JsonResponse
    {
        $iss = rtrim($this->issuer, '/');

        return $this->cached(new JsonResponse([
            'issuer' => $iss,
            'authorization_endpoint' => $iss.'/oauth/authorize',
            'token_endpoint' => $iss.'/oauth/token',
            'userinfo_endpoint' => $iss.'/userinfo',
            'jwks_uri' => $iss.'/.well-known/jwks.json',
            'end_session_endpoint' => $iss.'/logout',
            'scopes_supported' => $this->supportedScopes,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'name', 'preferred_username', 'email', 'email_verified', 'roles', 'global_roles', 'teams'],
        ]));
    }

    #[Route('/.well-known/jwks.json', name: 'oidc_jwks', methods: ['GET'])]
    public function jwks(): JsonResponse
    {
        return $this->cached(new JsonResponse(['keys' => [$this->keys->jwk()]]));
    }

    #[Route('/userinfo', name: 'oidc_userinfo', methods: ['GET', 'POST'])]
    public function userinfo(#[CurrentUser] User $user, TokenStorageInterface $tokens): JsonResponse
    {
        $token = $tokens->getToken();
        $scopes = $token instanceof OAuth2Token ? $token->getScopes() : [];
        $clientId = $token instanceof OAuth2Token ? $token->getOAuthClientId() : null;

        $claims = $this->claims->forSubject($user->getUuid(), $clientId, $scopes);
        if (null === $claims) {
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        return new JsonResponse(['sub' => $user->getUuid()] + $claims);
    }

    private function cached(JsonResponse $response): JsonResponse
    {
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
